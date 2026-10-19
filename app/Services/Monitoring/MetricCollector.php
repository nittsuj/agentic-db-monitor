<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Project;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;

class MetricCollector
{
    public function __construct(
        private readonly TargetConnectionManager $connections,
    ) {}

    /**
     * Read one round of metrics from a project's target database.
     *
     * @return array<string, float>
     */
    public function collect(Project $project): array
    {
        $connection = $this->connections->connect($project);

        try {
            return DiagnosticTools::driverFor($project->driver->value)
                ->collectMetrics($connection);
        } finally {
            $this->connections->purge($connection->getName());
        }
    }
}
