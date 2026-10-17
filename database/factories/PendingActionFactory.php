<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActionCategory;
use App\Enums\ActionStatus;
use App\Enums\DbDriver;
use App\Models\PendingAction;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingAction>
 */
class PendingActionFactory extends Factory
{
    protected $model = PendingAction::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'chat_session_id' => null,
            'engine' => DbDriver::MySql,
            'category' => ActionCategory::Maintenance,
            'command' => 'OPTIMIZE TABLE users;',
            'status' => ActionStatus::Pending,
            'meta' => null,
            'proposed_at' => now(),
            'decided_at' => null,
            'executed_at' => null,
        ];
    }
}
