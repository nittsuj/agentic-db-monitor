<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Chat\ChatWindow;
use App\Models\AiSetting;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\TestCase;

class ChatWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_persists_user_and_assistant_messages(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Hello from the assistant.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'Hi')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertCallCount(1);
        $fake->assertRequest(function ($recorded) {
            $this->assertNotEmpty($recorded[0]->tools());
        });

        $session = ChatSession::where('project_id', $project->id)->first();
        $this->assertNotNull($session);

        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Hello from the assistant.',
        ]);
    }

    public function test_send_ignored_when_no_active_project(): void
    {
        session()->forget('active_project_id');

        Livewire::test(ChatWindow::class)
            ->set('input', 'Hi')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('choose-project');

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_assistant_replies_render_markdown_and_escape_html(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);
        $session = ChatSession::factory()->for($project)->create();

        ChatMessage::factory()->for($session)->create([
            'role' => 'assistant',
            'content' => "## Status\n**CPU** tinggi\n\n| Metrik | Nilai |\n|---|---|\n| Load | 43 |\n\n<script>alert(1)</script>",
        ]);

        Livewire::test(ChatWindow::class)
            ->assertSeeHtml('<h2>Status</h2>')
            ->assertSeeHtml('<strong>CPU</strong>')
            ->assertSeeHtml('<td>Load</td>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_switches_the_ai_provider_from_the_chat(): void
    {
        $its = AiSetting::create(['provider' => 'openai_compatible', 'model' => 'qwen3:14b', 'base_url' => 'https://senopati.its.ac.id/llm-gateway/v1', 'api_key' => 'its-key', 'is_active' => true]);
        $dahl = AiSetting::create(['provider' => 'dahl', 'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', 'base_url' => 'https://inference.dahl.global/v1', 'api_key' => 'dahl-key', 'is_active' => false]);
        AiSetting::create(['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'base_url' => 'https://api.openai.com/v1', 'api_key' => null, 'is_active' => false]);

        Livewire::test(ChatWindow::class)
            ->assertSet('aiSettingId', $its->id)
            ->assertSee('dahl · deepseek-ai/DeepSeek-V4-Flash-0731')
            ->assertDontSee('openai · gpt-4.1-mini')
            ->set('aiSettingId', $dahl->id)
            ->assertDispatched('ai-provider-changed');

        $this->assertTrue($dahl->fresh()->is_active);
        $this->assertFalse($its->fresh()->is_active);
        $this->assertSame('its-key', $its->fresh()->api_key);
    }

    public function test_a_provider_without_a_key_cannot_be_selected(): void
    {
        $its = AiSetting::create(['provider' => 'openai_compatible', 'model' => 'qwen3:14b', 'api_key' => 'its-key', 'is_active' => true]);
        $openai = AiSetting::create(['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => null, 'is_active' => false]);

        Livewire::test(ChatWindow::class)
            ->set('aiSettingId', $openai->id)
            ->assertSet('aiSettingId', $its->id);

        $this->assertTrue($its->fresh()->is_active);
    }

    public function test_asks_to_choose_a_database_when_no_project_is_active(): void
    {
        session()->forget('active_project_id');
        $project = Project::factory()->create(['name' => 'Gudang Data']);

        Livewire::test(ChatWindow::class)
            ->assertSee('Pilih database dulu')
            ->assertSee('Gudang Data')
            ->assertSee(route('switch-project', ['project' => $project->id, 'to' => 'chat']), escape: false);
    }

    public function test_offers_to_add_a_connection_when_there_are_no_projects(): void
    {
        session()->forget('active_project_id');

        Livewire::test(ChatWindow::class)
            ->assertSee('Pilih database dulu')
            ->assertSee('Tambah koneksi database');
    }

    public function test_hides_the_dialog_when_a_project_is_active(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        Livewire::test(ChatWindow::class)->assertDontSee('Pilih database dulu');
    }

    public function test_a_deleted_active_project_counts_as_none(): void
    {
        session(['active_project_id' => '01a1556d-0000-7000-8000-000000000000']);

        Livewire::test(ChatWindow::class)
            ->assertSet('activeProjectId', null)
            ->assertSee('Pilih database dulu');
    }

    public function test_switching_project_can_return_to_the_chat(): void
    {
        $project = Project::factory()->create();

        $this->get(route('switch-project', ['project' => $project->id, 'to' => 'chat']))
            ->assertRedirect(route('chat'));
        $this->assertSame($project->id, session('active_project_id'));

        $this->get(route('switch-project', ['project' => $project->id, 'to' => 'https://evil.example']))
            ->assertRedirect(route('dashboard'));
    }

    public function test_send_includes_prior_conversation_context(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);
        $session = ChatSession::factory()->for($project)->create();
        ChatMessage::factory()->for($session)->create(['role' => 'user', 'content' => 'How many connections are active?']);
        ChatMessage::factory()->for($session)->create(['role' => 'assistant', 'content' => 'There are 12 active connections.']);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('What database is this?'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'What database is this?')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertRequest(function ($recorded) {
            $messages = $recorded[0]->messages();

            $this->assertCount(3, $messages);
            $this->assertInstanceOf(UserMessage::class, $messages[0]);
            $this->assertSame('How many connections are active?', $messages[0]->text());
            $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
            $this->assertSame('There are 12 active connections.', $messages[1]->content);
            $this->assertInstanceOf(UserMessage::class, $messages[2]);
            $this->assertSame('What database is this?', $messages[2]->text());
        });
    }

    public function test_send_uses_the_configured_provider(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);
        AiSetting::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-openai-key',
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Ready.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'Check the database.')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertRequest(function ($recorded) {
            $this->assertSame('openai', $recorded[0]->provider());
            $this->assertSame('gpt-4.1-mini', $recorded[0]->model());
        });
    }

    public function test_send_defaults_to_the_its_llm_gateway_when_unconfigured(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Ready.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'Check the database.')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertRequest(function ($recorded) {
            $this->assertSame('deepseek', $recorded[0]->provider());
            $this->assertSame('qwen3:14b', $recorded[0]->model());
        });
    }

    public function test_send_offers_the_diagnostic_and_telemetry_tools(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('Ready.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'How is the database doing?')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertRequest(function ($recorded) {
            $names = array_map(fn ($tool): string => $tool->name(), $recorded[0]->tools());

            $this->assertContains('list_tables', $names);
            $this->assertContains('get_metric_summary', $names);
            $this->assertContains('propose_mutation', $names);
        });
    }

    public function test_openai_compatible_provider_uses_chat_completions_driver_and_strips_thinking(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);
        AiSetting::factory()->create([
            'provider' => 'openai_compatible',
            'model' => 'qwen3:14b',
            'base_url' => 'https://senopati.its.ac.id/llm-gateway/v1',
            'api_key' => 'test-gateway-key',
        ]);

        $fake = Prism::fake([
            TextResponseFake::make()->withText('<think>internal reasoning</think>Ready.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'Check the database.')
            ->call('send')
            ->assertHasNoErrors();

        $fake->assertRequest(function ($recorded) {
            $this->assertSame('deepseek', $recorded[0]->provider());
            $this->assertSame('qwen3:14b', $recorded[0]->model());
        });

        $this->assertDatabaseHas('chat_messages', ['role' => 'assistant', 'content' => 'Ready.']);
    }
}
