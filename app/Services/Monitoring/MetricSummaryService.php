<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Enums\MetricKey;
use App\Models\MetricSample;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Summarises the telemetry the app recorded for a project so the agent can
 * ground an answer in measured data (FR-33).
 */
class MetricSummaryService
{
    public const DEFAULT_WINDOW_MINUTES = 15;

    public const MAX_WINDOW_MINUTES = 1440;

    /**
     * @return array<string, mixed>
     */
    public function summarize(Project $project, int $windowMinutes = self::DEFAULT_WINDOW_MINUTES): array
    {
        $samples = MetricSample::query()
            ->where('project_id', $project->id)
            ->where('captured_at', '>=', now()->subMinutes($windowMinutes))
            ->orderBy('captured_at')
            ->get();

        return $this->buildSummary($project->id, $samples, $windowMinutes);
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeById(string $projectId, int $windowMinutes = self::DEFAULT_WINDOW_MINUTES): array
    {
        $project = Project::find($projectId);

        if ($project === null) {
            return $this->emptySummary($projectId, $windowMinutes);
        }

        return $this->summarize($project, $windowMinutes);
    }

    /**
     * @param  Collection<int, MetricSample>  $samples
     * @return array<string, mixed>
     */
    public function buildSummary(string $projectId, Collection $samples, int $windowMinutes): array
    {
        if ($samples->isEmpty()) {
            return $this->emptySummary($projectId, $windowMinutes);
        }

        $grouped = $samples->groupBy('key');
        $metrics = [];

        foreach ($grouped as $key => $keySamples) {
            $values = $keySamples->map(fn (MetricSample $sample): float => (float) $sample->value)->all();
            $metricKey = MetricKey::tryFrom((string) $key);

            $metrics[] = [
                'key' => (string) $key,
                'label' => $metricKey?->label() ?? (string) $key,
                'unit' => $metricKey?->unit() ?? '',
                'samples' => count($values),
                'last' => round((float) end($values), 4),
                'min' => round(min($values), 4),
                'max' => round(max($values), 4),
                'mean' => round(array_sum($values) / count($values), 4),
                'trend' => $this->trend($values),
            ];
        }

        usort($metrics, fn (array $left, array $right): int => strcmp($left['key'], $right['key']));

        $meta = $samples->last()?->meta ?? [];
        $anomalous = $samples->filter(fn (MetricSample $sample): bool => (bool) ($sample->meta['anomaly'] ?? false))->count();

        return [
            'project_id' => $projectId,
            'window_minutes' => $windowMinutes,
            'generated_at' => now()->toIso8601String(),
            'source' => $meta['source'] ?? null,
            'scenario' => $meta['scenario'] ?? null,
            'config' => $meta['config'] ?? null,
            'sample_count' => $samples->count(),
            'anomalous_sample_count' => $anomalous,
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $projectId, int $windowMinutes): array
    {
        return [
            'project_id' => $projectId,
            'window_minutes' => $windowMinutes,
            'generated_at' => now()->toIso8601String(),
            'source' => null,
            'scenario' => null,
            'config' => null,
            'sample_count' => 0,
            'anomalous_sample_count' => 0,
            'metrics' => [],
        ];
    }

    /**
     * Compare the first and last third of the series.
     *
     * @param  array<int, float>  $values
     */
    private function trend(array $values): string
    {
        $count = count($values);

        if ($count < 4) {
            return 'flat';
        }

        $third = max(1, (int) floor($count / 3));
        $head = array_slice($values, 0, $third);
        $tail = array_slice($values, -$third);

        $headMean = array_sum($head) / count($head);
        $tailMean = array_sum($tail) / count($tail);
        $scale = max(abs($headMean), abs($tailMean), 1.0);

        if ($tailMean - $headMean > 0.05 * $scale) {
            return 'up';
        }

        if ($headMean - $tailMean > 0.05 * $scale) {
            return 'down';
        }

        return 'flat';
    }
}
