<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThresholdOperator;
use Database\Factories\ThresholdFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Threshold extends Model
{
    /** @use HasFactory<ThresholdFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'key',
        'operator',
        'value',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'operator' => ThresholdOperator::class,
            'value' => 'float',
            'enabled' => 'boolean',
        ];
    }

    public function isBreachedBy(float $sample): bool
    {
        return $this->enabled && $this->operator->matches($sample, $this->value);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
