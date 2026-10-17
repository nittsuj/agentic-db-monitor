<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\ChatSession;
use App\Models\PendingAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionLogPreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_delete_preserves_action_log(): void
    {
        $project = Project::factory()->create(['name' => 'Demo Project']);

        $log = ActionLog::factory()->create([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'engine' => $project->driver->value,
        ]);

        $project->delete();

        $this->assertDatabaseHas('action_logs', [
            'id' => $log->id,
            'project_id' => null,
            'project_name' => 'Demo Project',
            'engine' => $project->driver->value,
        ]);
    }

    public function test_chat_session_delete_nulls_pending_action_session(): void
    {
        $project = Project::factory()->create();
        $session = ChatSession::factory()->for($project)->create();

        $action = PendingAction::factory()->create([
            'project_id' => $project->id,
            'chat_session_id' => $session->id,
        ]);

        $session->delete();

        $this->assertDatabaseHas('pending_actions', [
            'id' => $action->id,
            'chat_session_id' => null,
        ]);
    }
}
