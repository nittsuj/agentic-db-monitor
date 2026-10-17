<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AiSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\View\View;
use Livewire\Component;

class AiSettingsForm extends Component
{
    public string $provider = 'ollama';

    public string $model = 'llama3.1';

    public string $base_url = 'https://ollama.com';

    public string $api_key_input = '';

    public bool $has_existing_key = false;

    private const ALLOWED_MODELS = ['llama3.1', 'llama3.2', 'qwen2.5'];

    public function mount(): void
    {
        $setting = AiSetting::where('is_active', true)->first();

        if ($setting !== null) {
            $this->provider = $setting->provider;
            $this->model = $setting->model ?? 'llama3.1';
            $this->base_url = $setting->base_url ?? 'https://ollama.com';
            try {
                $this->has_existing_key = $setting->api_key !== null && $setting->api_key !== '';
            } catch (DecryptException $e) {
                $this->has_existing_key = false;
            }
        }
    }

    protected function rules(): array
    {
        return [
            'provider' => ['required', 'in:ollama'],
            'model' => ['required', 'in:'.implode(',', self::ALLOWED_MODELS)],
            'base_url' => ['required', 'url'],
            'api_key_input' => [$this->has_existing_key ? 'nullable' : 'required', 'string', 'min:4'],
        ];
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

    public function render(): View
    {
        return view('livewire.settings.ai-settings-form');
    }
}
