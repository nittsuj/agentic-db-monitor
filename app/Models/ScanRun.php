<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\ScanRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ScanStatus $status
 * @property list<array{table: string, issues: list<string>}>|null $findings
 */
class ScanRun extends Model
{
    /** @use HasFactory<ScanRunFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'project_id',
        'status',
        'total',
        'processed',
        'current_table',
        'findings',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'total' => 'integer',
            'processed' => 'integer',
            'findings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Progress from 0 to 100, for the progress bar. */
    public function percent(): int
    {
        if ($this->status === ScanStatus::Done) {
            return 100;
        }

        return $this->total === 0 ? 0 : (int) floor($this->processed * 100 / $this->total);
    }
}
