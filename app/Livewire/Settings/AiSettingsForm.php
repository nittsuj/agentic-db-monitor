<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\ToolSupport;
use App\Models\AiSetting;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\ToolSupportVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Pengaturan AI — Agentic DB Monitor')]
class AiSettingsForm extends Component
{
    public string $provider = AiProviderConfig::DEFAULT_PROVIDER;

    public string $model = 'qwen3:14b';

    public string $base_url = 'https://senopati.its.ac.id/llm-gateway/v1';

    public string $api_key_input = '';

    public bool $has_existing_key = false;

    public ?string $test_result = null;

    public ?string $test_error = null;

    public function mount(): void
    {
        $setting = AiSetting::where('is_active', true)->first();

        if ($setting === null) {
            return;
        }

        $config = AiProviderConfig::resolve($setting);
        $this->provider = $config['provider'];
        $this->model = $config['model'];
        $this->base_url = $config['base_url'];
        $this->has_existing_key = $config['api_key'] !== '';
    }

    protected function rules(): array
    {
        $allowedModels = AiProviderConfig::allowedModels($this->provider);

        return [
            'provider' => ['required', Rule::in(AiProviderConfig::providers())],
            'model' => array_filter([
                'required',
                'string',
                'max:100',
                $allowedModels === null ? null : Rule::in($allowedModels),
            ]),
            'base_url' => ['required', 'url'],
            'api_key_input' => [
                AiProviderConfig::apiKeyRequired($this->provider) && ! $this->has_existing_key ? 'required' : 'nullable',
                'string',
                'min:4',
            ],
        ];
    }

    public function updatedProvider(string $provider): void
    {
        if (! AiProviderConfig::isValidProvider($provider)) {
            return;
        }

        $defaultUrls = array_map(
            fn (string $name): string => AiProviderConfig::defaults($name)['base_url'],
            AiProviderConfig::providers()
        );

        // Only swap in defaults when the current URL is another provider's default,
        // so a custom URL the user typed is never overwritten.
        if (in_array($this->base_url, $defaultUrls, true)) {
            $defaults = AiProviderConfig::defaults($provider);
            $this->base_url = $defaults['base_url'];
            $this->model = $defaults['model'];
        }
    }

    public function save(): void
    {
        $this->validate();

        $setting = AiSetting::where('is_active', true)->first();

        if ($setting === null) {
            $setting = new AiSetting(['is_active' => true]);
        }

        $setting->fill([
            'provider' => $this->provider,
            'model' => $this->model,
            'base_url' => $this->base_url,
        ]);

        if ($this->api_key_input !== '') {
            $setting->api_key = $this->api_key_input;
        }

        $setting->save();

        $this->api_key_input = '';
        $this->has_existing_key = true;

        session()->flash('status', 'AI settings saved.');
    }

    public function testConnection(): void
    {
        $this->test_result = null;
        $this->test_error = null;

        if (! in_array($this->provider, ['openai', AiProviderConfig::OPENAI_COMPATIBLE], true)) {
            $this->test_error = 'Connection testing is available for OpenAI-compatible providers only.';

            return;
        }

        $key = trim($this->api_key_input) !== ''
            ? $this->api_key_input
            : AiProviderConfig::apiKey(AiSetting::where('is_active', true)->first());

        if (trim($key) === '') {
            $this->test_error = 'Enter an API key before testing the connection.';

            return;
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->post(AiProviderConfig::chatCompletionsUrl($this->base_url), [
                    'model' => $this->model,
                    'messages' => [['role' => 'user', 'content' => 'Reply with the word OK.']],
                    'max_tokens' => 8,
                ]);
        } catch (ConnectionException) {
            $this->test_error = 'Could not reach the provider. Check the base URL and your network connection.';

            return;
        }

        if ($response->successful()) {
            $support = app(ToolSupportVerifier::class)->verify([
                'provider' => $this->provider,
                'model' => $this->model,
                'base_url' => $this->base_url,
                'api_key' => $key,
            ]);

            $this->test_result = 'Connection successful. '.$this->toolSupportMessage($support);

            return;
        }

        $this->test_error = match ($response->status()) {
            401 => 'Authentication failed (401). Check the API key.',
            403 => 'Access denied (403). This account may not be allowed to use the model.',
            404 => 'Endpoint or model not found (404). Check the base URL and model name.',
            429 => 'Rate limited (429). Wait a moment and try again.',
            default => "Connection failed (HTTP {$response->status()}).",
        };
    }

    public function render(): View
    {
        return view('livewire.settings.ai-settings-form');
    }

    private function toolSupportMessage(ToolSupport $support): string
    {
        return match ($support) {
            ToolSupport::Supported => 'The provider supports function calling, so the diagnostic tools are available.',
            ToolSupport::Unsupported => 'The provider rejected function calling, so the chat will answer without diagnostic tools.',
            ToolSupport::Unknown => 'Function calling support could not be verified.',
        };
    }
}
