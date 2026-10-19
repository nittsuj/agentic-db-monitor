<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Project;
use App\Models\Threshold;
use App\Models\User;
use App\Notifications\ThresholdBreached;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class ThresholdEvaluator
{
    /**
     * A breaching threshold notifies at most once per this window.
     */
    public const COOLDOWN_MINUTES = 15;

    /**
     * @param  array<string, float>  $samples
     * @return array<int, array{threshold: Threshold, value: float}>
     */
    public function evaluate(Project $project, array $samples, ?CarbonInterface $capturedAt = null): array
    {
        $capturedAt ??= now();
        $breaches = [];

        $thresholds = $project->thresholds()->where('enabled', true)->get();

        foreach ($thresholds as $threshold) {
            if (! array_key_exists($threshold->key, $samples)) {
                continue;
            }

            $value = (float) $samples[$threshold->key];

            if (! $threshold->isBreachedBy($value)) {
                continue;
            }

            $breaches[] = ['threshold' => $threshold, 'value' => $value];

            Log::warning('Threshold breached.', [
                'project_id' => $project->id,
                'metric' => $threshold->key,
                'value' => $value,
                'threshold' => $threshold->value,
            ]);

            $cooldownKey = "threshold-breach:{$threshold->id}";

            if (Cache::has($cooldownKey)) {
                continue;
            }

            Cache::put($cooldownKey, true, now()->addMinutes(self::COOLDOWN_MINUTES));

            $users = User::query()->get();

            if ($users->isNotEmpty()) {
                Notification::send(
                    $users,
                    new ThresholdBreached($project, $threshold, $value, $capturedAt)
                );
            }
        }

        return $breaches;
    }
}
