<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\MetricKey;
use App\Enums\ThresholdOperator;
use App\Services\Dbpa\DbpaCsvReader;

/**
 * Derives a default threshold per metric from the difference between the
 * Normal distribution and the anomaly distribution of the DBPA dataset
 * (FR-34). A metric is only recommended when the two distributions are
 * actually separable; overlapping metrics are reported as such instead of
 * producing a threshold that would fire on healthy traffic.
 */
class ThresholdRecommendationService
{
    public function __construct(
        private readonly DbpaCsvReader $reader = new DbpaCsvReader,
        private readonly CaseSummary $caseSummary = new CaseSummary,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, unit: string, operator: string, value: float, normal_percentile: float, anomaly_median: float, coverage: float, precision: float, separable: bool}>
     */
    public function recommend(string $engine, string $config, string $root): array
    {
        $normalValues = $this->normalValues($root, $engine, $config);
        $anomalyValues = $this->anomalyValues($root, $engine, $config);

        $recommendations = [];

        foreach (MetricKey::values() as $key) {
            $normal = $normalValues[$key] ?? [];
            $anomaly = $anomalyValues[$key] ?? [];

            if (count($normal) < 5 || count($anomaly) < 5) {
                continue;
            }

            $metricKey = MetricKey::from($key);
            $higherIsWorse = $metricKey->higherIsWorse();

            $percentile = $higherIsWorse
                ? $this->percentile($normal, 0.95)
                : $this->percentile($normal, 0.05);

            $coverage = $this->shareBeyond($anomaly, $percentile, $higherIsWorse);
            $precision = 1 - $this->shareBeyond($normal, $percentile, $higherIsWorse);
            $anomalyMedian = $this->median($anomaly);
            $separable = $coverage >= 0.5;

            $recommendations[] = [
                'key' => $key,
                'label' => $metricKey->label(),
                'unit' => $metricKey->unit(),
                'operator' => $higherIsWorse ? ThresholdOperator::Gt->value : ThresholdOperator::Lt->value,
                'value' => round($percentile, 2),
                'normal_percentile' => round($percentile, 2),
                'anomaly_median' => round($anomalyMedian, 2),
                'coverage' => round($coverage, 4),
                'precision' => round($precision, 4),
                'separable' => $separable,
            ];
        }

        return $recommendations;
    }

    /**
     * @return array<string, array<int, float>>
     */
    private function normalValues(string $root, string $engine, string $config): array
    {
        $path = $this->reader->scenarioPath($root, $engine, $config, 'normal');

        if (! is_file($path)) {
            return [];
        }

        return $this->valuesOf($this->reader->readCases($path), false);
    }

    /**
     * @return array<string, array<int, float>>
     */
    private function anomalyValues(string $root, string $engine, string $config): array
    {
        $directory = rtrim($root, '/')."/{$engine}/{$config}";
        $values = [];

        foreach ($this->reader->scenarios($directory) as $scenario) {
            if ($scenario === 'normal') {
                continue;
            }

            $path = $this->reader->scenarioPath($root, $engine, $config, $scenario);

            if (! is_file($path)) {
                continue;
            }

            foreach ($this->valuesOf($this->reader->readCases($path), true) as $key => $keyValues) {
                foreach ($keyValues as $value) {
                    $values[$key][] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<int, array<int, array{anomaly: bool, metrics: array<string, float>}>>  $cases
     * @return array<string, array<int, float>>
     */
    private function valuesOf(array $cases, bool $onlyAnomalous): array
    {
        $values = [];

        foreach ($cases as $rows) {
            foreach ($rows as $row) {
                if ($onlyAnomalous && ! $row['anomaly']) {
                    continue;
                }

                foreach ($row['metrics'] as $key => $value) {
                    $values[$key][] = (float) $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function percentile(array $values, float $quantile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $position = (int) round($quantile * (count($values) - 1));
        $position = max(0, min($position, count($values) - 1));

        return $values[$position];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        return $this->percentile($values, 0.5);
    }

    /**
     * Share of the samples that cross the threshold in the harmful direction.
     *
     * @param  array<int, float>  $values
     */
    private function shareBeyond(array $values, float $threshold, bool $higherIsWorse): float
    {
        if ($values === []) {
            return 0.0;
        }

        $beyond = 0;

        foreach ($values as $value) {
            if ($higherIsWorse ? $value > $threshold : $value < $threshold) {
                $beyond++;
            }
        }

        return $beyond / count($values);
    }
}
