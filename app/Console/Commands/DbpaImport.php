<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MetricSample;
use App\Models\Project;
use App\Services\Dbpa\DbpaCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DbpaImport extends Command
{
    private const SAMPLE_INTERVAL_SECONDS = 5;

    protected $signature = 'dbpa:import
                            {--project= : Project id to import into (defaults to the first project)}
                            {--engine=pgsql : pgsql or mysql}
                            {--config=32_128 : DBPA hardware configuration directory}
                            {--scenario=normal : Scenario CSV to import}
                            {--path= : CSV root (defaults to database/dbpa/csv)}
                            {--cases=10 : Maximum number of cases to import}
                            {--no-normal : Do not prepend a Normal case before each anomalous case}';

    protected $description = 'Import DBPA CSV samples into a project for the replay dashboard.';

    public function handle(): int
    {
        $engine = (string) $this->option('engine');
        $config = (string) $this->option('config');
        $scenario = (string) $this->option('scenario');
        $cases = max(1, (int) $this->option('cases'));

        if (! in_array($engine, ['pgsql', 'mysql'], true)) {
            $this->error('The --engine option must be pgsql or mysql.');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $config) || ! preg_match('/^[A-Za-z0-9_+]+$/', $scenario)) {
            $this->error('Invalid --config or --scenario value.');

            return self::FAILURE;
        }

        $project = $this->resolveProject();

        if ($project === null) {
            $this->error('No project found. Pass --project=<id> after creating a database profile.');

            return self::FAILURE;
        }

        $root = (string) ($this->option('path') ?: database_path('dbpa/csv'));
        $reader = app(DbpaCsvReader::class);
        $path = $reader->scenarioPath($root, $engine, $config, $scenario);

        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");
            $this->line('Run database/dbpa/convert_dbpa.py against DBPA_dataset first.');

            return self::FAILURE;
        }

        try {
            $importedCases = $reader->readCases($path);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $selected = array_slice($importedCases, 0, $cases, true);

        if ($selected === []) {
            $this->error("No cases found in {$path}.");

            return self::FAILURE;
        }

        $normalCase = $this->normalCase($reader, $root, $engine, $config, $scenario);

        $timeline = $this->buildTimeline($selected, $normalCase);
        $start = now()->subSeconds(max(0, (count($timeline) - 1) * self::SAMPLE_INTERVAL_SECONDS));

        MetricSample::query()
            ->where('project_id', $project->id)
            ->delete();

        $inserted = 0;

        foreach (array_chunk($timeline, 250) as $offset => $chunk) {
            $records = [];

            foreach ($chunk as $index => $row) {
                $position = $offset * 250 + $index;
                $capturedAt = $start->copy()->addSeconds($position * self::SAMPLE_INTERVAL_SECONDS);
                $timestamp = $capturedAt->toDateTimeString();

                foreach ($row['metrics'] as $key => $value) {
                    $records[] = [
                        'id' => (string) Str::uuid7(),
                        'project_id' => $project->id,
                        'key' => $key,
                        'value' => $value,
                        'meta' => json_encode([
                            'source' => 'dbpa',
                            'engine' => $engine,
                            'config' => $config,
                            'scenario' => $scenario,
                            'case' => $row['case'],
                            'anomaly' => $row['anomaly'],
                        ], JSON_THROW_ON_ERROR),
                        'captured_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }

            if ($records !== []) {
                DB::table('metric_samples')->insert($records);
                $inserted += count($records);
            }
        }

        $this->info("Imported {$inserted} sample(s) for {$project->name} from {$engine}/{$config}/{$scenario}.");
        $this->line(count($selected).' case(s), replay window ends at '.now()->toDateTimeString().'.');
        $this->line('Replay replaces the previous telemetry of this project, so live and replay data are never mixed.');

        return self::SUCCESS;
    }

    private function resolveProject(): ?Project
    {
        $id = $this->option('project');

        if (is_string($id) && $id !== '') {
            return Project::find($id);
        }

        return Project::query()->orderBy('created_at')->first();
    }

    /**
     * @return array<int, array<int, array{anomaly: bool, metrics: array<string, float>}>>
     */
    private function normalCase(
        DbpaCsvReader $reader,
        string $root,
        string $engine,
        string $config,
        string $scenario,
    ): array {
        if ($scenario === 'normal' || $this->option('no-normal')) {
            return [];
        }

        $path = $reader->scenarioPath($root, $engine, $config, 'normal');

        if (! is_file($path)) {
            return [];
        }

        $cases = $reader->readCases($path);

        if ($cases === []) {
            return [];
        }

        return (array) reset($cases);
    }

    /**
     * @param  array<int, array<int, array{anomaly: bool, metrics: array<string, float>}>>  $cases
     * @param  array<int, array{anomaly: bool, metrics: array<string, float>}>  $normalCase
     * @return array<int, array{case: int, anomaly: bool, metrics: array<string, float>}>
     */
    private function buildTimeline(array $cases, array $normalCase): array
    {
        $timeline = [];

        foreach ($cases as $caseNumber => $rows) {
            foreach ($normalCase as $normalRow) {
                $timeline[] = [
                    'case' => $caseNumber,
                    'anomaly' => false,
                    'metrics' => $normalRow['metrics'],
                ];
            }

            foreach ($rows as $row) {
                $timeline[] = [
                    'case' => $caseNumber,
                    'anomaly' => $row['anomaly'],
                    'metrics' => $row['metrics'],
                ];
            }
        }

        return $timeline;
    }
}
