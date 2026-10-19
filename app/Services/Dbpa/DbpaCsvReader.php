<?php

declare(strict_types=1);

namespace App\Services\Dbpa;

use App\Enums\MetricKey;
use RuntimeException;

/**
 * Reads the CSV files produced by database/dbpa/convert_dbpa.py. The Laravel
 * app never touches a DBPA pickle; only these normalised files.
 */
class DbpaCsvReader
{
    /**
     * Read every case in a scenario CSV, keyed by case number.
     *
     * @return array<int, array<int, array{anomaly: bool, metrics: array<string, float>}>>
     */
    public function readCases(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false) {
                throw new RuntimeException("Empty CSV: {$path}.");
            }

            $metricKeys = array_values(array_intersect(MetricKey::values(), $header));

            if ($metricKeys === []) {
                throw new RuntimeException("No known metric columns in {$path}.");
            }

            $cases = [];

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($record === [null] || count($record) !== count($header)) {
                    continue;
                }

                $row = array_combine($header, $record);

                if ($row === false || ! isset($row['case'])) {
                    continue;
                }

                $metrics = [];

                foreach ($metricKeys as $key) {
                    $raw = $row[$key] ?? null;

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $metrics[$key] = (float) $raw;
                }

                if ($metrics === []) {
                    continue;
                }

                $cases[(int) $row['case']][] = [
                    'anomaly' => (int) ($row['anomaly'] ?? 0) === 1,
                    'metrics' => $metrics,
                ];
            }

            return $cases;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Scenario names available in a directory, without the file extension.
     *
     * @return array<int, string>
     */
    public function scenarios(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $scenarios = [];

        foreach ((array) glob(rtrim($directory, '/').'/*.csv') as $file) {
            if (! is_string($file)) {
                continue;
            }

            $scenarios[] = pathinfo($file, PATHINFO_FILENAME);
        }

        sort($scenarios);

        return $scenarios;
    }

    /**
     * Absolute path of a scenario CSV inside a CSV root.
     */
    public function scenarioPath(string $root, string $engine, string $config, string $scenario): string
    {
        return rtrim($root, '/')."/{$engine}/{$config}/{$scenario}.csv";
    }
}
