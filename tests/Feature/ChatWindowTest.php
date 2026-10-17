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
            ->assertHasNoErrors();

        $this->assertSame(0, ChatMessage::count());
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
}
