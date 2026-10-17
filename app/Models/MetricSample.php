<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MetricSampleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSample extends Model
{
    /** @use HasFactory<MetricSampleFactory> */
    use HasFactory;

    use HasUuids;
    use Prunable;

    protected $fillable = [
        'project_id',
        'key',
        'value',
        'meta',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'meta' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function prunable()
    {
        return static::where('captured_at', '<=', now()->subHours(48));
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
