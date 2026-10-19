<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\AiSettingsForm;
use App\Models\AiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiSettingsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_form(): void
    {
        Livewire::test(AiSettingsForm::class)->assertSee('Pengaturan AI');
    }

    public function test_defaults_to_the_its_llm_gateway(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->assertSet('provider', 'openai_compatible')
            ->assertSet('model', 'qwen3:14b')
            ->assertSet('base_url', 'https://senopati.its.ac.id/llm-gateway/v1');
    }

    public function test_gateway_rejects_models_outside_the_allow_list(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('model', 'some-other-model')
            ->set('api_key_input', 'gateway-secret-key')
            ->call('save')
            ->assertHasErrors(['model']);
    }

    public function test_saves_new_settings_and_encrypts_key(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'ollama')
            ->set('model', 'llama3.2')
            ->set('base_url', 'https://ollama.com')
            ->set('api_key_input', 'super-secret-key')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::where('is_active', true)->first();
        $this->assertNotNull($setting);
        $this->assertSame('llama3.2', $setting->model);
        $this->assertSame('super-secret-key', $setting->api_key);

        $raw = \DB::table('ai_settings')->where('id', $setting->id)->value('api_key');
        $this->assertNotSame('super-secret-key', $raw);
    }

    public function test_saves_openai_settings_with_api_key(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'openai')
            ->set('model', 'gpt-4.1-mini')
            ->set('base_url', 'https://api.openai.com/v1')
            ->set('api_key_input', 'openai-secret-key')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::where('is_active', true)->first();

        $this->assertSame('openai', $setting->provider);
        $this->assertSame('openai-secret-key', $setting->api_key);
    }

    public function test_switching_to_openai_compatible_fills_gateway_defaults(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'openai_compatible')
            ->assertSet('base_url', 'https://senopati.its.ac.id/llm-gateway/v1')
            ->assertSet('model', 'qwen3:14b')
            ->set('api_key_input', 'gateway-secret-key')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::where('is_active', true)->first();

        $this->assertSame('openai_compatible', $setting->provider);
        $this->assertSame('gateway-secret-key', $setting->api_key);
    }

    public function test_switching_to_dahl_fills_its_defaults_and_accepts_any_model(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'dahl')
            ->assertSet('base_url', 'https://inference.dahl.global/v1')
            ->assertSet('model', 'deepseek-ai/DeepSeek-V4-Flash-0731')
            ->set('model', 'some-org/another-model')
            ->set('api_key_input', 'dahl-secret-key')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::where('is_active', true)->first();

        $this->assertSame('dahl', $setting->provider);
        $this->assertSame('some-org/another-model', $setting->model);
        $this->assertSame('dahl-secret-key', $setting->api_key);
    }

    public function test_a_key_saved_for_one_provider_is_not_reused_for_another(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'gateway-secret-key')
            ->call('save')
            ->assertHasNoErrors();

        Http::fake();

        Livewire::test(AiSettingsForm::class)
            ->assertSet('has_existing_key', true)
            ->set('provider', 'dahl')
            ->assertSet('has_existing_key', false)
            ->call('testConnection')
            ->assertSet('test_error', 'Enter an API key before testing the connection.')
            ->call('save')
            ->assertHasErrors(['api_key_input' => 'required']);

        Http::assertNothingSent();
        $this->assertSame('gateway-secret-key', AiSetting::where('is_active', true)->first()->api_key);
    }

    public function test_each_provider_keeps_its_own_key(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'its-key')
            ->call('save');

        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'dahl')
            ->set('api_key_input', 'dahl-key')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, AiSetting::where('is_active', true)->count());
        $this->assertSame('dahl', AiSetting::where('is_active', true)->value('provider'));
        $this->assertSame('its-key', AiSetting::where('provider', 'openai_compatible')->first()->api_key);

        // Switching back finds the ITS key again, so no new key is needed.
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'openai_compatible')
            ->assertSet('has_existing_key', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('openai_compatible', AiSetting::where('is_active', true)->value('provider'));
    }

    public function test_connection_test_calls_the_dahl_endpoint(): void
    {
        Http::fake([
            'inference.dahl.global/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
        ]);

        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'dahl')
            ->set('api_key_input', 'dahl-secret-key')
            ->call('testConnection')
            ->assertSet('test_error', null);

        Http::assertSent(fn ($request) => $request->url() === 'https://inference.dahl.global/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer dahl-secret-key')
            && $request['model'] === 'deepseek-ai/DeepSeek-V4-Flash-0731');
    }

    public function test_requires_api_key_for_new_openai_settings(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'openai')
            ->set('model', 'gpt-4.1-mini')
            ->set('base_url', 'https://api.openai.com/v1')
            ->call('save')
            ->assertHasErrors(['api_key_input']);
    }

    public function test_empty_key_preserves_existing(): void
    {
        $existing = AiSetting::factory()->create(['api_key' => 'original-key']);

        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('original-key', $existing->fresh()->api_key);
    }

    public function test_rejects_unknown_provider(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('provider', 'unknown')
            ->set('api_key_input', 'some-key')
            ->call('save')
            ->assertHasErrors(['provider']);
    }

    public function test_rejects_invalid_base_url(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->set('base_url', 'not-a-url')
            ->set('api_key_input', 'some-key')
            ->call('save')
            ->assertHasErrors(['base_url']);
    }

    public function test_connection_reports_success(): void
    {
        Http::fake([
            'senopati.its.ac.id/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
        ]);

        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'gateway-secret-key')
            ->call('testConnection')
            ->assertSet('test_result', 'Connection successful. The provider supports function calling, so the diagnostic tools are available.')
            ->assertSet('test_error', null);

        Http::assertSent(fn ($request) => $request->url() === 'https://senopati.its.ac.id/llm-gateway/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gateway-secret-key')
            && $request['model'] === 'qwen3:14b');
    }

    public function test_connection_reports_a_provider_without_function_calling(): void
    {
        Http::fake(function ($request) {
            if (isset($request['tools'])) {
                return Http::response(['error' => ['message' => 'tools is not supported by this model']], 400);
            }

            return Http::response(['choices' => [['message' => ['content' => 'OK']]]]);
        });

        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'gateway-secret-key')
            ->call('testConnection')
            ->assertSet('test_result', 'Connection successful. The provider rejected function calling, so the chat will answer without diagnostic tools.');
    }

    public function test_connection_reports_a_rejected_key(): void
    {
        Http::fake([
            'senopati.its.ac.id/*' => Http::response(['error' => 'invalid key'], 401),
        ]);

        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'wrong-key')
            ->call('testConnection')
            ->assertSet('test_result', null)
            ->assertSet('test_error', 'Authentication failed (401). Check the API key.');
    }

    public function test_connection_reports_rate_limiting(): void
    {
        Http::fake([
            'senopati.its.ac.id/*' => Http::response(['error' => 'slow down'], 429),
        ]);

        Livewire::test(AiSettingsForm::class)
            ->set('api_key_input', 'gateway-secret-key')
            ->call('testConnection')
            ->assertSet('test_error', 'Rate limited (429). Wait a moment and try again.');
    }

    public function test_connection_requires_a_key(): void
    {
        Livewire::test(AiSettingsForm::class)
            ->call('testConnection')
            ->assertSet('test_error', 'Enter an API key before testing the connection.');
    }
}
