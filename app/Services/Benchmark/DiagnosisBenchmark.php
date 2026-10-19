<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Services\Dbpa\DbpaCsvReader;
use Throwable;

/**
 * Runs the labelled DBPA benchmark: for every scenario the agent sees the
 * metric deviations of a case and has to name the root cause (FR-33).
 */
class DiagnosisBenchmark
{
    public function __construct(
        private readonly DbpaCsvReader $reader = new DbpaCsvReader,
        private readonly CaseSummary $caseSummary = new CaseSummary,
    ) {}

    /**
     * @param  array<int, string>  $scenarios
     * @return array<int, array{scenario: string, cases: int, correct: int, accuracy: float, details: array<int, array<string, mixed>>}>
     */
    public function run(
        string $engine,
        string $config,
        string $root,
        array $scenarios,
        int $casesPerScenario,
        ScenarioPredictor $predictor,
    ): array {
        $baseline = $this->baseline($root, $engine, $config);
        $labels = $this->labels($scenarios);
        $results = [];

        foreach ($scenarios as $scenario) {
            $path = $this->reader->scenarioPath($root, $engine, $config, $scenario);

            if (! is_file($path)) {
                continue;
            }

            $cases = $this->reader->readCases($path);
            $details = [];
            $correct = 0;
            $evaluated = 0;

            foreach (array_slice($cases, 0, max(1, $casesPerScenario), true) as $caseNumber => $rows) {
                $summary = $this->caseSummary->build($scenario, (int) $caseNumber, $rows, $baseline);
                $error = null;
                $prediction = '';

                try {
                    $prediction = $predictor->predict($summary, $labels);
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }

                $matched = $error === null ? $this->match($prediction, $labels) : null;
                $isCorrect = $matched === $scenario;

                $evaluated++;
                $correct += $isCorrect ? 1 : 0;

                $details[] = [
                    'case' => (int) $caseNumber,
                    'expected' => $scenario,
                    'predicted' => $matched ?? ($error === null ? trim($prediction) : null),
                    'correct' => $isCorrect,
                    'error' => $error,
                    'deviation' => $summary['deviation'],
                ];
            }

            if ($evaluated === 0) {
                continue;
            }

            $results[] = [
                'scenario' => $scenario,
                'cases' => $evaluated,
                'correct' => $correct,
                'accuracy' => round($correct / $evaluated, 4),
                'details' => $details,
            ];
        }

        return $results;
    }

    /**
     * Mean of every Normal row for the same engine and hardware config.
     *
     * @return array<string, float>
     */
    public function baseline(string $root, string $engine, string $config): array
    {
        $path = $this->reader->scenarioPath($root, $engine, $config, 'normal');

        if (! is_file($path)) {
            return [];
        }

        $cases = $this->reader->readCases($path);
        $rows = [];

        foreach ($cases as $caseRows) {
            foreach ($caseRows as $row) {
                $rows[] = $row;
            }
        }

        return $this->caseSummary->means($rows);
    }

    /**
     * @param  array<int, string>  $scenarios
     * @return array<int, string>
     */
    private function labels(array $scenarios): array
    {
        $labels = array_values(array_filter($scenarios, fn (string $scenario): bool => $scenario !== 'normal'));

        usort($labels, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $labels;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function match(string $prediction, array $labels): ?string
    {
        $normalized = strtolower($prediction);
        $normalized = (string) preg_replace('/[^a-z0-9_]+/', '_', $normalized);

        foreach ($labels as $label) {
            if (str_contains($normalized, strtolower($label))) {
                return $label;
            }
        }

        return null;
    }
}
