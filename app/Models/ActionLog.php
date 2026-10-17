<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionDecision;
use App\Enums\DbDriver;
use Database\Factories\ActionLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    /** @use HasFactory<ActionLogFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'project_name',
        'pending_action_id',
        'chat_session_id',
        'engine',
        'command',
        'decision',
        'result',
        'error',
        'actor',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'engine' => DbDriver::class,
            'decision' => ActionDecision::class,
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
