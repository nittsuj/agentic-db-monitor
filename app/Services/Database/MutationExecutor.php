<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\PendingAction;
use App\Models\Project;

class MutationExecutor
{
    public function __construct(
        protected TargetConnectionManager $connectionManager,
    ) {}

    public function execute(PendingAction $action): array
    {
        $project = Project::find($action->project_id);

        if ($project === null) {
            return ['success' => false, 'error' => 'Project no longer exists.'];
        }

        $connection = $this->connectionManager->connect($project);

        try {
            $connection->statement($action->command);

            return ['success' => true, 'result' => 'Executed successfully.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->connectionManager->purge($connection->getName());
        }
    }
}
