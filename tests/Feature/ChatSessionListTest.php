<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Chat\SessionList;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatSessionListTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->alice = User::factory()->create();
        session(['active_project_id' => $this->project->id]);
    }

    public function test_lists_only_the_users_chats_on_the_active_project_most_recent_first(): void
    {
        ChatSession::factory()->for($this->project)->for($this->alice)->create(['title' => 'Older chat', 'updated_at' => now()->subDay()]);
        ChatSession::factory()->for($this->project)->for($this->alice)->create(['title' => 'Recent chat', 'updated_at' => now()]);
        ChatSession::factory()->for($this->project)->for(User::factory())->create(['title' => 'Bob chat']);
        ChatSession::factory()->for(Project::factory())->for($this->alice)->create(['title' => 'Other project chat']);

        Livewire::actingAs($this->alice)->test(SessionList::class)
            ->assertSeeInOrder(['Recent chat', 'Older chat'])
            ->assertDontSee('Bob chat')
            ->assertDontSee('Other project chat');
    }

    public function test_an_untitled_chat_is_shown_as_a_new_chat(): void
    {
        ChatSession::factory()->for($this->project)->for($this->alice)->create(['title' => null]);

        Livewire::actingAs($this->alice)->test(SessionList::class)->assertSee('Chat baru');
    }

    public function test_selecting_a_chat_tells_the_chat_window(): void
    {
        $session = ChatSession::factory()->for($this->project)->for($this->alice)->create();

        Livewire::actingAs($this->alice)->test(SessionList::class)
            ->call('select', $session->id)
            ->assertDispatched('session-selected', sessionId: $session->id);
    }

    public function test_a_chat_the_list_does_not_show_cannot_be_selected(): void
    {
        $bobSession = ChatSession::factory()->for($this->project)->for(User::factory())->create();

        Livewire::actingAs($this->alice)->test(SessionList::class)
            ->call('select', $bobSession->id)
            ->assertNotDispatched('session-selected');
    }

    public function test_new_chat_creates_a_session_and_opens_it(): void
    {
        $component = Livewire::actingAs($this->alice)->test(SessionList::class)->call('newChat');

        $session = ChatSession::where('user_id', $this->alice->id)->sole();

        $this->assertSame($this->project->id, $session->project_id);
        $this->assertNull($session->title);
        $component->assertDispatched('session-selected', sessionId: $session->id);
    }

    public function test_new_chat_reuses_an_empty_chat_instead_of_piling_them_up(): void
    {
        $used = ChatSession::factory()->for($this->project)->for($this->alice)->create();
        ChatMessage::factory()->for($used)->create();

        Livewire::actingAs($this->alice)->test(SessionList::class)->call('newChat')->call('newChat');

        $this->assertSame(2, ChatSession::where('user_id', $this->alice->id)->count());
    }

    public function test_refreshes_when_a_message_is_sent(): void
    {
        $session = ChatSession::factory()->for($this->project)->for($this->alice)->create(['title' => null]);

        $component = Livewire::actingAs($this->alice)->test(SessionList::class)->assertDontSee('Index advice');

        $session->update(['title' => 'Index advice']);

        $component->dispatch('message-sent', sessionId: $session->id)->assertSee('Index advice');
    }

    public function test_highlights_the_open_chat(): void
    {
        $session = ChatSession::factory()->for($this->project)->for($this->alice)->create();

        Livewire::actingAs($this->alice)->test(SessionList::class, ['activeSessionId' => $session->id])
            ->assertSeeHtml('aria-current="true"');
    }

    public function test_without_an_active_project_there_is_nothing_to_list_or_create(): void
    {
        session()->forget('active_project_id');

        Livewire::actingAs($this->alice)->test(SessionList::class)
            ->assertSee('Pilih database dulu.')
            ->call('newChat')
            ->assertNotDispatched('session-selected');

        $this->assertSame(0, ChatSession::count());
    }
}
