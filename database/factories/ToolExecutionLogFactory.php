<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ToolExecutionStatus;
use App\Models\ChatMessage;
use App\Models\QueryDiagnostician;
use App\Models\ToolExecutionLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ToolExecutionLog>
 */
class ToolExecutionLogFactory extends Factory
{
    protected $model = ToolExecutionLog::class;

    public function definition(): array
    {
        return [
            'chat_message_id' => ChatMessage::factory(),
            // The Query Diagnostician's own row, inserted by its migration.
            'loggable_type' => (new QueryDiagnostician)->getMorphClass(),
            'loggable_id' => fn (): int => QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->value('id'),
            'tool_name' => 'list_tables',
            'arguments' => [],
            'result' => '{"tables":["orders","customers"]}',
            'status' => ToolExecutionStatus::Success,
            'error' => null,
            'duration_ms' => fake()->numberBetween(5, 1500),
        ];
    }

    /**
     * Run by the given agent, with one of the tools it handles.
     *
     * @param  Model&object{handles: list<string>}  $agent
     */
    public function forAgent(Model $agent): static
    {
        return $this->state(fn (): array => [
            'loggable_type' => $agent->getMorphClass(),
            'loggable_id' => $agent->getKey(),
            'tool_name' => fake()->randomElement($agent->handles),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn (): array => [
            'status' => ToolExecutionStatus::Success,
            'error' => null,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'status' => ToolExecutionStatus::Error,
            'result' => null,
            'error' => 'Connection to the target database timed out.',
        ]);
    }
}
