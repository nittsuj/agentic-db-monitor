<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Chat sessions from before the app had accounts have no user, so nobody can
 * see them. The first account created is the person who has been using the
 * app until now, so it takes them over. Later accounts never do.
 */
class ClaimOrphanChatSessions
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || User::query()->whereKeyNot($user->getKey())->exists()) {
            return;
        }

        ChatSession::query()->whereNull('user_id')->update(['user_id' => $user->getKey()]);
    }
}
