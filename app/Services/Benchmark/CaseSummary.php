<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Services\Monitoring\MetricDeviation;

/**
 * Builds the metric summary that is handed to the agent for one DBPA case.
 * The deviation uses the same log2 formula as the offline converter, so the
 * benchmark and the imported samples tell the same story.
 */
class CaseSummary
{
    /**
     * @param  array<int, array{anomaly: bool, metrics: array<string, float>}>  $rows
     * @param  array<string, float>  $baseline
     * @return array<string, mixed>
     */
    public function build(string $scenario, int $caseNumber, array $rows, array $baseline): array
    {
        $windowRows = array_values(array_filter($rows, fn (array $row): bool => $row['anomaly']));

        if ($windowRows === []) {
            $windowRows = $rows;
        }

        $window = $this->means($windowRows);
        $deviation = [];
        $signals = [];

        foreach ($window as $key => $value) {
            $base = $baseline[$key] ?? 0.0;
            $ratio = $this->deviation($value, $base);

            $deviation[$key] = $ratio;

            $signals[] = [
                'key' => $key,
                'baseline' => round($base, 4),
                'window_mean' => round($value, 4),
                'deviation' => $ratio,
                'direction' => $ratio > 0 ? 'up' : ($ratio < 0 ? 'down' : 'flat'),
            ];
        }

        usort($signals, fn (array $left, array $right): int => abs($right['deviation']) <=> abs($left['deviation']));

        return [
            'scenario' => $scenario,
            'case' => $caseNumber,
            'samples' => count($rows),
            'anomalous_samples' => count($windowRows),
            'baseline' => array_map(fn (float $value): float => round($value, 4), $baseline),
            'deviation' => $deviation,
            'signals' => array_slice($signals, 0, 8),
        ];
    }

    /**
     * @param  array<int, array{anomaly: bool, metrics: array<string, float>}>  $rows
     * @return array<string, float>
     */
    public function means(array $rows): array
    {
        $totals = [];
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row['metrics'] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0.0) + $value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $means = [];

        foreach ($totals as $key => $total) {
            $means[$key] = $total / max(1, $counts[$key]);
        }

        return $means;
    }

    /**
     * log2((|window| + eps) / (|base| + eps)), with eps keeping tiny
     * baselines from exploding into huge ratios.
     */
    public function deviation(float $window, float $baseline): float
    {
        return MetricDeviation::log2Ratio($window, $baseline);
    }

    /**
     * Average of every value per metric key.
     *
     * @param  array<string, array<int, float>>  $values
     * @return array<string, float>
     */
    public function meanOfValues(array $values): array
    {
        $means = [];

        foreach ($values as $key => $list) {
            $means[$key] = array_sum($list) / max(1, count($list));
        }

        return $means;
    }
}
