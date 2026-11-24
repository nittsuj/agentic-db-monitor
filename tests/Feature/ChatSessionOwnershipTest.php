<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Chat\ChatWindow;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use RuntimeException;
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

    public function test_the_browser_cannot_point_the_chat_at_another_users_session(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceSession = ChatSession::factory()->for($this->project)->for($alice)->create();

        try {
            Livewire::actingAs($bob)->test(ChatWindow::class)->set('chatSessionId', $aliceSession->id);
            $this->fail('The session id could be changed from the browser.');
        } catch (CannotUpdateLockedPropertyException) {
            // Expected: the property is locked.
        }

        $this->assertSame(0, $aliceSession->messages()->count());
    }

    public function test_selecting_a_session_opens_it_and_shows_its_messages(): void
    {
        $alice = User::factory()->create();
        $older = ChatSession::factory()->for($this->project)->for($alice)->create(['created_at' => now()->subDay()]);
        ChatMessage::factory()->for($older)->create(['role' => 'user', 'content' => 'Question from yesterday']);
        $newer = ChatSession::factory()->for($this->project)->for($alice)->create();

        Livewire::actingAs($alice)->test(ChatWindow::class)
            ->assertSet('chatSessionId', $newer->id)
            ->assertDontSee('Question from yesterday')
            ->dispatch('session-selected', sessionId: $older->id)
            ->assertSet('chatSessionId', $older->id)
            ->assertSee('Question from yesterday');
    }

    public function test_selecting_another_users_session_is_refused(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceSession = ChatSession::factory()->for($this->project)->for($alice)->create();
        ChatMessage::factory()->for($aliceSession)->create(['role' => 'user', 'content' => 'Alice private question']);

        $component = Livewire::actingAs($bob)->test(ChatWindow::class);
        $bobSession = $component->get('chatSessionId');

        $component->dispatch('session-selected', sessionId: $aliceSession->id)
            ->assertSet('chatSessionId', $bobSession)
            ->assertDontSee('Alice private question');
    }

    public function test_selecting_a_session_of_another_project_is_refused(): void
    {
        $alice = User::factory()->create();
        $otherProject = ChatSession::factory()->for(Project::factory())->for($alice)->create();

        $component = Livewire::actingAs($alice)->test(ChatWindow::class);
        $current = $component->get('chatSessionId');

        $component->dispatch('session-selected', sessionId: $otherProject->id)
            ->assertSet('chatSessionId', $current);
    }

    public function test_a_new_chat_is_named_after_its_first_prompt(): void
    {
        $alice = User::factory()->create();
        Prism::fake([
            TextResponseFake::make()->withText('First reply.'),
            TextResponseFake::make()->withText('Second reply.'),
        ]);

        $component = Livewire::actingAs($alice)->test(ChatWindow::class);
        $session = ChatSession::findOrFail($component->get('chatSessionId'));
        $this->assertNull($session->title);

        $component->set('input', "  Why are   connections\npiling up on the orders table every night around midnight?  ")->call('send');
        $component->set('input', 'And what about the users table?')->call('send');

        $this->assertSame('Why are connections piling up on the orders table every...', $session->fresh()->title);
    }

    public function test_a_chat_with_a_title_keeps_it(): void
    {
        $alice = User::factory()->create();
        $session = ChatSession::factory()->for($this->project)->for($alice)->create(['title' => 'Slow queries']);
        Prism::fake([TextResponseFake::make()->withText('Reply.')]);

        Livewire::actingAs($alice)->test(ChatWindow::class)->set('input', 'Something else')->call('send');

        $this->assertSame('Slow queries', $session->fresh()->title);
    }

    public function test_sending_tells_the_session_list_and_marks_the_chat_as_recent(): void
    {
        $alice = User::factory()->create();
        $session = ChatSession::factory()->for($this->project)->for($alice)->create(['updated_at' => now()->subWeek()]);
        Prism::fake([TextResponseFake::make()->withText('Reply.')]);

        Livewire::actingAs($alice)->test(ChatWindow::class)
            ->set('input', 'Any locks?')
            ->call('send')
            ->assertDispatched('message-sent', sessionId: $session->id);

        $this->assertTrue($session->fresh()->updated_at->isAfter(now()->subMinute()));
    }

    public function test_a_failed_reply_still_tells_the_session_list(): void
    {
        $alice = User::factory()->create();
        Prism::shouldReceive('text')->andThrow(new RuntimeException('Provider unreachable'));

        Livewire::actingAs($alice)->test(ChatWindow::class)
            ->set('input', 'Any locks?')
            ->call('send')
            ->assertSet('errorMessage', fn ($message) => $message !== null)
            ->assertDispatched('message-sent');
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
