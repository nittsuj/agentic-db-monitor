<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'engine',
        'config',
        'scenario',
        'cases',
        'correct',
        'accuracy',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'cases' => 'integer',
            'correct' => 'integer',
            'accuracy' => 'float',
            'details' => 'array',
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
