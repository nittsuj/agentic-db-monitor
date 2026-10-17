<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Chat\ChatWindow;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class ChatWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_persists_user_and_assistant_messages(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        Prism::fake([
            TextResponseFake::make()->withText('Hello from the assistant.'),
        ]);

        Livewire::test(ChatWindow::class)
            ->set('input', 'Hi')
            ->call('send')
            ->assertHasNoErrors();

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
}
