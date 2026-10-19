<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionCategory;
use App\Enums\ActionStatus;
use App\Enums\DbDriver;
use Carbon\CarbonInterface;
use Database\Factories\PendingActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    /** @use HasFactory<PendingActionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Actions older than this are no longer actionable.
     */
    public const EXPIRES_AFTER_HOURS = 24;

    protected $fillable = [
        'project_id',
        'chat_session_id',
        'engine',
        'category',
        'command',
        'status',
        'meta',
        'proposed_at',
        'decided_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'engine' => DbDriver::class,
            'category' => ActionCategory::class,
            'status' => ActionStatus::class,
            'meta' => 'array',
            'proposed_at' => 'datetime',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Mark stale pending actions as expired and return the number changed.
     */
    public static function expireStale(?CarbonInterface $now = null): int
    {
        $now ??= now();

        return static::query()
            ->where('status', ActionStatus::Pending)
            ->where('proposed_at', '<=', $now->copy()->subHours(self::EXPIRES_AFTER_HOURS))
            ->update([
                'status' => ActionStatus::Expired,
                'decided_at' => $now,
            ]);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
