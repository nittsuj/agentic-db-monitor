<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only chat history of the signed-in user, with every tool the assistant
 * ran. Only the user's own sessions can be listed or opened.
 */
class ChatHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = ChatSession::query()
            ->whereBelongsTo($request->user())
            ->with('project')
            ->withCount('messages')
            ->withMax('messages', 'created_at')
            ->orderByDesc('messages_max_created_at')
            ->latest()
            ->get();

        return view('chat.history.index', ['sessions' => $sessions]);
    }

    /**
     * One conversation with its messages, their tool logs and each log's agent,
     * in a fixed number of queries however long it is (withFullHistory).
     */
    public function show(Request $request, string $session): View
    {
        $session = ChatSession::query()
            ->whereBelongsTo($request->user())
            ->with('project')
            ->withFullHistory()
            ->findOrFail($session);

        return view('chat.history.show', ['session' => $session]);
    }
}
