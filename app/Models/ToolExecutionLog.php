<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ToolExecutionStatus;
use Database\Factories\ToolExecutionLogFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class ToolExecutionLog extends Model
{
    /** @use HasFactory<ToolExecutionLogFactory> */
    use HasFactory;

    use HasUuids;

    /** Longest tool result kept; a large result set would bloat the table. */
    public const MAX_RESULT_LENGTH = 10_000;

    protected $fillable = [
        'chat_message_id',
        'loggable_type',
        'loggable_id',
        'tool_name',
        'arguments',
        'result',
        'status',
        'error',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'status' => ToolExecutionStatus::class,
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    /**
     * The agent that ran the tool.
     *
     * @return MorphTo<Model, $this>
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function result(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : Str::limit($value, self::MAX_RESULT_LENGTH, '… [truncated]'),
        );
    }
}
