<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActionCategory;
use App\Enums\ActionStatus;
use App\Livewire\Approvals\ApprovalQueue;
use App\Models\PendingAction;
use App\Models\Project;
use App\Services\Database\MutationExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_executes_and_logs(): void
    {
        $project = Project::factory()->create();
        $action = PendingAction::factory()->create([
            'project_id' => $project->id,
            'engine' => $project->driver->value,
            'category' => ActionCategory::Maintenance,
            'command' => 'ANALYZE TABLE events',
            'status' => ActionStatus::Pending,
        ]);

        $mock = \Mockery::mock(MutationExecutor::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'result' => 'Executed successfully.']);
        $this->app->instance(MutationExecutor::class, $mock);

        Livewire::test(ApprovalQueue::class)
            ->call('approve', $action->id)
            ->assertHasNoErrors();

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertDatabaseHas('action_logs', [
            'pending_action_id' => $action->id,
            'decision' => 'executed',
        ]);
    }

    public function test_deny_logs_without_executing(): void
    {
        $project = Project::factory()->create();
        $action = PendingAction::factory()->create([
            'project_id' => $project->id,
            'engine' => $project->driver->value,
            'category' => ActionCategory::Ddl,
            'command' => 'DROP TABLE events',
            'status' => ActionStatus::Pending,
        ]);

        Livewire::test(ApprovalQueue::class)
            ->call('deny', $action->id)
            ->assertHasNoErrors();

        $this->assertSame(ActionStatus::Denied, $action->fresh()->status);
        $this->assertDatabaseHas('action_logs', [
            'pending_action_id' => $action->id,
            'decision' => 'denied',
        ]);
    }
}
