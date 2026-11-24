<?php

declare(strict_types=1);

namespace App\Livewire\Chat;

use App\Models\ChatSession;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The signed-in user's chats on the active project, next to the chat window.
 *
 * Talks to ChatWindow only through events: picking or starting a chat sends
 * `session-selected`, and ChatWindow sends `message-sent` after every prompt
 * so the list re-sorts and picks up the new title.
 */
class SessionList extends Component
{
    /** How many recent chats the list shows. */
    public const LIMIT = 30;

    /** The chat open in ChatWindow, passed down so the list can highlight it. */
    #[Reactive]
    public ?string $activeSessionId = null;

    #[Locked]
    public ?string $projectId = null;

    public function mount(): void
    {
        $projectId = session('active_project_id');

        $this->projectId = $projectId !== null && Project::whereKey($projectId)->exists()
            ? (string) $projectId
            : null;
    }

    /**
     * @return Collection<int, ChatSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        if ($this->projectId === null) {
            return new Collection;
        }

        return ChatSession::query()
            ->where('user_id', auth()->id())
            ->where('project_id', $this->projectId)
            ->withCount('messages')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();
    }

    public function select(string $sessionId): void
    {
        // ChatWindow checks ownership again; this only avoids sending ids the
        // list never showed.
        if ($this->sessions->contains('id', $sessionId)) {
            $this->dispatch('session-selected', sessionId: $sessionId);
        }
    }

    /**
     * Start a chat, or reuse an empty one, so pressing the button twice does
     * not leave a trail of blank chats behind.
     */
    public function newChat(): void
    {
        if ($this->projectId === null) {
            return;
        }

        $session = ChatSession::query()
            ->where('user_id', auth()->id())
            ->where('project_id', $this->projectId)
            ->whereDoesntHave('messages')
            ->latest('updated_at')
            ->first();

        if ($session === null) {
            $session = ChatSession::create([
                'user_id' => auth()->id(),
                'project_id' => $this->projectId,
                'title' => null,
            ]);
        } else {
            $session->touch();
        }

        unset($this->sessions);
        $this->dispatch('session-selected', sessionId: $session->id);
    }

    #[On('message-sent')]
    public function refreshSessions(): void
    {
        unset($this->sessions);
    }

    public function render(): View
    {
        return view('livewire.chat.session-list');
    }
}
