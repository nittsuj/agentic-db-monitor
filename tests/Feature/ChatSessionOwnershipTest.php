<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Chat\ChatWindow;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class ChatSessionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        session(['active_project_id' => $this->project->id]);
    }

    public function test_each_user_gets_their_own_session_on_the_same_project(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceSession = Livewire::actingAs($alice)->test(ChatWindow::class)->get('chatSessionId');
        $bobSession = Livewire::actingAs($bob)->test(ChatWindow::class)->get('chatSessionId');

        $this->assertNotSame($aliceSession, $bobSession);
        $this->assertSame($alice->id, ChatSession::find($aliceSession)->user_id);
        $this->assertSame($bob->id, ChatSession::find($bobSession)->user_id);

        // Opening the chat again reuses the same session.
        $this->assertSame($aliceSession, Livewire::actingAs($alice)->test(ChatWindow::class)->get('chatSessionId'));
    }

    public function test_a_user_never_sees_another_users_messages(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $session = ChatSession::factory()->for($this->project)->for($alice)->create();
        ChatMessage::factory()->for($session)->create(['role' => 'user', 'content' => 'Alice private question']);

        Livewire::actingAs($alice)->test(ChatWindow::class)->assertSee('Alice private question');
        Livewire::actingAs($bob)->test(ChatWindow::class)->assertDontSee('Alice private question');
    }

    public function test_messages_are_saved_in_the_senders_session(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceSession = ChatSession::factory()->for($this->project)->for($alice)->create();

        Prism::fake([TextResponseFake::make()->withText('Reply for Bob.')]);

        Livewire::actingAs($bob)->test(ChatWindow::class)
            ->set('input', 'Bob asks')
            ->call('send')
            ->assertHasNoErrors();

        $bobSession = ChatSession::where('user_id', $bob->id)->sole();

        $this->assertSame(['Bob asks', 'Reply for Bob.'], $bobSession->messages()->orderBy('created_at')->pluck('content')->all());
        $this->assertSame(0, $aliceSession->messages()->count());
    }

    public function test_old_sessions_without_a_user_are_not_shown_to_anyone(): void
    {
        $old = ChatSession::create(['project_id' => $this->project->id, 'title' => 'Old chat']);
        ChatMessage::factory()->for($old)->create(['role' => 'user', 'content' => 'Message from before users']);

        $component = Livewire::actingAs(User::factory()->create())->test(ChatWindow::class);

        $component->assertDontSee('Message from before users');
        $this->assertNotSame($old->id, $component->get('chatSessionId'));
    }

    public function test_without_a_signed_in_user_no_session_is_loaded_or_created(): void
    {
        ChatSession::create(['project_id' => $this->project->id, 'title' => 'Old chat']);

        Livewire::test(ChatWindow::class)->assertSet('chatSessionId', null);

        $this->assertSame(1, ChatSession::count());
    }
}
