<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ThresholdOperator;
use App\Models\Project;
use App\Models\Threshold;
use App\Services\Benchmark\ThresholdRecommendationService;
use Illuminate\Console\Command;

class DbpaRecommendThresholds extends Command
{
    protected $signature = 'dbpa:recommend-thresholds
                            {--engine=pgsql : pgsql or mysql}
                            {--config=32_128 : DBPA hardware configuration directory}
                            {--path= : CSV root (defaults to database/dbpa/csv)}
                            {--project= : Project the thresholds are written to}
                            {--apply : Save the recommended thresholds for the project}';

    protected $description = 'Recommend default threshold values per metric from the DBPA Normal and anomaly distributions.';

    public function handle(): int
    {
        $engine = (string) $this->option('engine');
        $config = (string) $this->option('config');

        if (! in_array($engine, ['pgsql', 'mysql'], true)) {
            $this->error('The --engine option must be pgsql or mysql.');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $config)) {
            $this->error('Invalid --config value.');

            return self::FAILURE;
        }

        $root = (string) ($this->option('path') ?: database_path('dbpa/csv'));
        $directory = rtrim($root, '/')."/{$engine}/{$config}";

        if (! is_dir($directory)) {
            $this->error("DBPA CSV directory not found: {$directory}");
            $this->line('Run database/dbpa/convert_dbpa.py against DBPA_dataset first.');

            return self::FAILURE;
        }

        $recommendations = app(ThresholdRecommendationService::class)->recommend($engine, $config, $root);

        if ($recommendations === []) {
            $this->error('No recommendation could be derived. Check the scenario files.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($recommendations as $recommendation) {
            $rows[] = [
                $recommendation['label'],
                $recommendation['operator'].' '.$recommendation['value'],
                $recommendation['coverage'].' coverage',
                $recommendation['separable'] ? 'yes' : 'no (distributions overlap)',
            ];
        }

        $this->table(['Metric', 'Recommended', 'Anomaly', 'Separable'], $rows);

        if (! $this->option('apply')) {
            $this->line('Re-run with --apply --project=<id> to store these thresholds.');

            return self::SUCCESS;
        }

        $project = $this->resolveProject();

        if ($project === null) {
            $this->error('A project id is required to apply thresholds. Pass --project=<id>.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($recommendations as $recommendation) {
            if (! $recommendation['separable']) {
                $skipped++;

                continue;
            }

            $operator = ThresholdOperator::from($recommendation['operator']);
            $threshold = Threshold::query()
                ->where('project_id', $project->id)
                ->where('key', $recommendation['key'])
                ->first();

            if ($threshold === null) {
                Threshold::create([
                    'project_id' => $project->id,
                    'key' => $recommendation['key'],
                    'operator' => $operator,
                    'value' => $recommendation['value'],
                    'enabled' => true,
                ]);

                $created++;

                continue;
            }

            // The recommendation is the default for this metric, so it replaces
            // the current rule instead of leaving the old value in place.
            $threshold->update([
                'operator' => $operator,
                'value' => $recommendation['value'],
            ]);

            $updated++;
        }

        $this->info("Created {$created} threshold(s), updated {$updated}, skipped {$skipped} for {$project->name}.");

        return self::SUCCESS;
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
