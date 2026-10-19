<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BenchmarkReport;
use App\Models\Project;
use App\Services\Benchmark\DiagnosisBenchmark;
use App\Services\Benchmark\LlmScenarioPredictor;
use App\Services\Dbpa\DbpaCsvReader;
use Illuminate\Console\Command;

class DbpaBenchmark extends Command
{
    protected $signature = 'dbpa:benchmark
                            {--engine=pgsql : pgsql or mysql}
                            {--config=32_128 : DBPA hardware configuration directory}
                            {--path= : CSV root (defaults to database/dbpa/csv)}
                            {--cases=3 : Number of cases evaluated per scenario}
                            {--scenarios= : Comma separated scenarios (defaults to every scenario except normal)}
                            {--project= : Project the report is attached to}';

    protected $description = 'Benchmark the agent diagnosis accuracy against the labelled DBPA scenarios.';

    public function handle(): int
    {
        $engine = (string) $this->option('engine');
        $config = (string) $this->option('config');
        $cases = max(1, (int) $this->option('cases'));

        if (! in_array($engine, ['pgsql', 'mysql'], true)) {
            $this->error('The --engine option must be pgsql or mysql.');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $config)) {
            $this->error('Invalid --config value.');

            return self::FAILURE;
        }

        $root = (string) ($this->option('path') ?: database_path('dbpa/csv'));
        $reader = app(DbpaCsvReader::class);
        $directory = rtrim($root, '/')."/{$engine}/{$config}";

        if (! is_dir($directory)) {
            $this->error("DBPA CSV directory not found: {$directory}");
            $this->line('Run database/dbpa/convert_dbpa.py against DBPA_dataset first.');

            return self::FAILURE;
        }

        $scenarios = $this->scenarios($reader, $root, $engine, $config);

        if ($scenarios === []) {
            $this->error("No DBPA scenarios found in {$directory}.");

            return self::FAILURE;
        }

        $project = $this->resolveProject();

        $results = app(DiagnosisBenchmark::class)->run(
            $engine,
            $config,
            $root,
            $scenarios,
            $cases,
            app(LlmScenarioPredictor::class),
        );

        if ($results === []) {
            $this->error('The benchmark produced no result. Check the scenario files.');

            return self::FAILURE;
        }

        $rows = [];
        $failures = 0;

        foreach ($results as $result) {
            foreach ($result['details'] as $detail) {
                if (($detail['error'] ?? null) !== null) {
                    $failures++;
                }
            }

            BenchmarkReport::create([
                'project_id' => $project?->id,
                'engine' => $engine,
                'config' => $config,
                'scenario' => $result['scenario'],
                'cases' => $result['cases'],
                'correct' => $result['correct'],
                'accuracy' => $result['accuracy'],
                'details' => $result['details'],
            ]);

            $rows[] = [
                $result['scenario'],
                $result['cases'],
                $result['correct'],
                number_format($result['accuracy'] * 100, 1).'%',
            ];
        }

        $this->table(['Scenario', 'Cases', 'Correct', 'Accuracy'], $rows);

        $evaluated = array_sum(array_column($results, 'cases'));
        $correct = array_sum(array_column($results, 'correct'));
        $overall = $evaluated > 0 ? round($correct / $evaluated, 4) : 0.0;

        $this->info(sprintf(
            'Overall accuracy: %d/%d (%s) for %s/%s.',
            $correct,
            $evaluated,
            number_format($overall * 100, 1).'%',
            $engine,
            $config
        ));

        if ($failures > 0) {
            $this->warn("{$failures} case(s) could not be answered by the provider; they are counted as wrong.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function scenarios(DbpaCsvReader $reader, string $root, string $engine, string $config): array
    {
        $requested = (string) ($this->option('scenarios') ?: '');

        if ($requested !== '') {
            $scenarios = array_values(array_filter(array_map(
                fn (string $scenario): string => trim($scenario),
                explode(',', $requested)
            )));

            return array_values(array_filter(
                $scenarios,
                fn (string $scenario): bool => is_file($reader->scenarioPath($root, $engine, $config, $scenario))
            ));
        }

        return array_values(array_filter(
            $reader->scenarios(rtrim($root, '/')."/{$engine}/{$config}"),
            fn (string $scenario): bool => $scenario !== 'normal'
        ));
    }

    private function resolveProject(): ?Project
    {
        $id = $this->option('project');

        if (is_string($id) && $id !== '') {
            return Project::find($id);
        }

        return null;
    }
}
