<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActionDecision;
use App\Enums\DbDriver;
use App\Models\ActionLog;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionLog>
 */
class ActionLogFactory extends Factory
{
    protected $model = ActionLog::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_name' => fake()->company().' DB',
            'pending_action_id' => null,
            'chat_session_id' => null,
            'engine' => DbDriver::MySql,
            'command' => 'ANALYZE TABLE users;',
            'decision' => ActionDecision::Executed,
            'result' => 'OK',
            'error' => null,
            'actor' => 'user',
            'executed_at' => now(),
        ];
    }
}
