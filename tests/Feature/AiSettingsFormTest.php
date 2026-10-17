<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\AiSettingsForm;
use App\Models\AiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiSettingsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_form(): void
    {
        Livewire::test(AiSettingsForm::class)->assertSee('AI Provider Settings');
    }

    public function test_saves_new_settings_and_encrypts_key(): void
    {
        Livewire::test(AiSettingsForm::class)
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
}
