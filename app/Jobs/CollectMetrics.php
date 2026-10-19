<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MetricSample;
use App\Models\Project;
use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public ?string $projectId = null) {}

    public function handle(MetricCollector $collector, ThresholdEvaluator $evaluator): void
    {
        $projects = Project::query()
            ->where('is_active', true)
            ->when($this->projectId !== null, fn ($query) => $query->whereKey($this->projectId))
            ->get();

        foreach ($projects as $project) {
            try {
                $samples = $collector->collect($project);

                if ($samples === []) {
                    continue;
                }

                $capturedAt = now();

                foreach ($samples as $key => $value) {
                    MetricSample::create([
                        'project_id' => $project->id,
                        'key' => $key,
                        'value' => $value,
                        'meta' => ['source' => 'collector'],
                        'captured_at' => $capturedAt,
                    ]);
                }

                $evaluator->evaluate($project, $samples, $capturedAt);
            } catch (Throwable $exception) {
                Log::warning('Metric collection failed for project.', [
                    'project_id' => $project->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
