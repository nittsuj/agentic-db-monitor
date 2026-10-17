<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DbDriver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'driver' => DbDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MetricSample, $this>
     */
    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    /**
     * @return HasMany<Threshold, $this>
     */
    public function thresholds(): HasMany
    {
        return $this->hasMany(Threshold::class);
    }

    /**
     * @return HasMany<PendingAction, $this>
     */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    /**
     * @return HasMany<ActionLog, $this>
     */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }
}
