<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChatRole;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'chat_session_id' => ChatSession::factory(),
            'role' => ChatRole::User,
            'content' => fake()->sentence(),
            'meta' => null,
        ];
    }
}
