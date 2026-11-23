<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One /api/chat-ai exchange. Uses an integer key (see its migration), not HasUuids.
 */
class AiChatLog extends Model
{
    use Prunable;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'prompt',
        'ai_response',
        'provider',
        'model_used',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'is_streamed',
        'latency_ms',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'is_streamed' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * Deleted by the hourly model:prune once older than config('llm-hub.retention_days').
     */
    public function prunable()
    {
        return static::where('created_at', '<=', now()->subDays((int) config('llm-hub.retention_days')));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
