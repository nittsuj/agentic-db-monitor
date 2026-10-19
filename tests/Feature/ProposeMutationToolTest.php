<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Project;
use App\Services\Ai\ProposeMutationTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Tool;
use Tests\TestCase;

class ProposeMutationToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_multiple_statements(): void
    {
        [$tool] = $this->makeTool();

        $result = $this->invokeTool($tool, 'SELECT 1; DROP TABLE users');

        $this->assertSame('rejected', $result['status']);
        $this->assertDatabaseCount('pending_actions', 0);
    }

    public function test_rejects_read_only_statements(): void
    {
        [$tool] = $this->makeTool();

        $result = $this->invokeTool($tool, 'SELECT * FROM users');

        $this->assertSame('rejected', $result['status']);
        $this->assertDatabaseCount('pending_actions', 0);
    }

    public function test_queues_a_single_mutating_statement_for_approval(): void
    {
        [$tool, $project, $session] = $this->makeTool();

        $result = $this->invokeTool($tool, "UPDATE users SET role = 'admin' WHERE id = 1");

        $this->assertSame('pending_approval', $result['status']);
        $this->assertSame('dml', $result['category']);
        $this->assertDatabaseHas('pending_actions', [
            'id' => $result['pending_action_id'],
            'project_id' => $project->id,
            'chat_session_id' => $session->id,
            'status' => 'pending',
            'command' => "UPDATE users SET role = 'admin' WHERE id = 1",
        ]);
    }

    /**
     * @return array{0: Tool, 1: Project, 2: ChatSession}
     */
    private function makeTool(): array
    {
        $project = Project::factory()->create();
        $session = ChatSession::factory()->for($project)->create();

        return [ProposeMutationTool::make($project, $session->id), $project, $session];
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeTool(Tool $tool, string $command): array
    {
        $output = $tool->handle(command: $command);

        $this->assertIsString($output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
