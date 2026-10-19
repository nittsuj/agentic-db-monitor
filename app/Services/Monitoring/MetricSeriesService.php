<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\MetricKey;
use App\Models\MetricSample;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the chart series and the anomaly signals of a project. Short ranges
 * keep the raw five-second samples, longer ranges are aggregated per minute
 * so the charts stay light (FR-36).
 */
class MetricSeriesService
{
    public const RAW_RANGE_MINUTES = 15;

    /**
     * Metrics that are reported as a peak inside a minute instead of an
     * average, because a single lock spike is the interesting event.
     */
    private const PEAK_METRICS = ['locks', 'lock_waiting_sessions', 'long_running_queries'];

    /**
     * @return Collection<string, array{
     *     key: string,
     *     latest: float,
     *     captured_at: Carbon,
     *     min: float,
     *     max: float,
     *     mean: float,
     *     values: Collection<int, float>,
     *     samples: int
     * }>
     */
    public function series(Project $project, int $minutes): Collection
    {
        $samples = $project->metricSamples()
            ->where('captured_at', '>=', now()->subMinutes($minutes))
            ->orderBy('captured_at')
            ->get(['key', 'value', 'captured_at', 'meta']);

        if ($samples->isEmpty()) {
            return collect();
        }

        return $samples->groupBy('key')->map(function (Collection $keySamples, string $key) use ($minutes): array {
            $values = $this->aggregate($keySamples, $minutes);
            $latest = $keySamples->last();

            return [
                'key' => $key,
                'latest' => (float) $latest->value,
                'captured_at' => $latest->captured_at,
                'min' => min($values),
                'max' => max($values),
                'mean' => round(array_sum($values) / count($values), 4),
                'values' => collect($values),
                'samples' => $keySamples->count(),
            ];
        });
    }

    /**
     * The strongest deviations between the anomaly window and the Normal
     * segment of a replay (PRD section 10.4).
     *
     * @return array<int, array{key: string, label: string, deviation: float, baseline: float, window: float}>
     */
    public function anomalySignals(Project $project, int $minutes): array
    {
        $samples = $project->metricSamples()
            ->where('captured_at', '>=', now()->subMinutes($minutes))
            ->get(['key', 'value', 'meta']);

        if ($samples->isEmpty()) {
            return [];
        }

        $anomalous = $samples->filter(fn (MetricSample $sample): bool => (bool) ($sample->meta['anomaly'] ?? false));
        $normal = $samples->filter(fn (MetricSample $sample): bool => ! (bool) ($sample->meta['anomaly'] ?? false));

        if ($anomalous->isEmpty() || $normal->isEmpty()) {
            return [];
        }

        $signals = [];
        $baselineMeans = $this->means($normal);

        foreach ($this->means($anomalous) as $key => $window) {
            $baseline = $baselineMeans[$key] ?? 0.0;
            $metricKey = MetricKey::tryFrom((string) $key);

            $signals[] = [
                'key' => (string) $key,
                'label' => $metricKey?->displayName() ?? (string) $key,
                'deviation' => MetricDeviation::log2Ratio($window, $baseline),
                'baseline' => round($baseline, 4),
                'window' => round($window, 4),
            ];
        }

        usort($signals, fn (array $left, array $right): int => abs($right['deviation']) <=> abs($left['deviation']));

        return array_slice($signals, 0, 5);
    }

    /**
     * @param  Collection<int, MetricSample>  $samples
     * @return array<int, float>
     */
    private function aggregate(Collection $samples, int $minutes): array
    {
        if ($minutes <= self::RAW_RANGE_MINUTES) {
            return $samples->map(fn (MetricSample $sample): float => (float) $sample->value)->all();
        }

        $key = (string) $samples->first()->key;
        $peak = in_array($key, self::PEAK_METRICS, true);

        return $samples
            ->groupBy(fn (MetricSample $sample): string => $sample->captured_at->format('Y-m-d H:i'))
            ->map(function (Collection $minute) use ($peak): float {
                $values = $minute->map(fn (MetricSample $sample): float => (float) $sample->value);

                return $peak ? (float) $values->max() : (float) $values->avg();
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MetricSample>  $samples
     * @return array<string, float>
     */
    private function means(Collection $samples): array
    {
        $values = [];

        foreach ($samples as $sample) {
            $values[(string) $sample->key][] = (float) $sample->value;
        }

        $means = [];

        foreach ($values as $key => $list) {
            $means[$key] = array_sum($list) / max(1, count($list));
        }

        return $means;
    }
}
