<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    /** @use HasFactory<ChatSessionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'user_id',
        'title',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * The whole conversation in a fixed number of queries, however long it is:
     * the session, its messages, their tool logs, and one query per agent
     * type that ran a tool (at most 3). Without it, reading each message's
     * logs and each log's agent would run a query per row (N+1).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithFullHistory(Builder $query): void
    {
        $query->with([
            'messages' => fn ($messages) => $messages->orderBy('created_at'),
            'messages.toolExecutionLogs' => fn ($logs) => $logs->orderBy('created_at'),
            'messages.toolExecutionLogs.loggable',
        ]);
    }
}
