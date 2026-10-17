<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChatRole;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'chat_session_id',
        'role',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'role' => ChatRole::class,
            'meta' => 'array',
        ];
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }
}
