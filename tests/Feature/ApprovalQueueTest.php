<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActionCategory;
use App\Enums\ActionStatus;
use App\Livewire\Approvals\ApprovalQueue;
use App\Models\ChatSession;
use App\Models\PendingAction;
use App\Models\Project;
use App\Services\Database\MutationExecutor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_executes_and_logs(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

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
            ->assertHasNoErrors()
            ->assertDispatched('approvals-changed');

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertDatabaseHas('action_logs', [
            'pending_action_id' => $action->id,
            'decision' => 'executed',
        ]);
    }

    public function test_deny_logs_without_executing(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $action = PendingAction::factory()->create([
            'project_id' => $project->id,
            'engine' => $project->driver->value,
            'category' => ActionCategory::Ddl,
            'command' => 'DROP TABLE events',
            'status' => ActionStatus::Pending,
        ]);

        Livewire::test(ApprovalQueue::class)
            ->call('deny', $action->id)
            ->assertHasNoErrors()
            ->assertDispatched('approvals-changed');

        $this->assertSame(ActionStatus::Denied, $action->fresh()->status);
        $this->assertDatabaseHas('action_logs', [
            'pending_action_id' => $action->id,
            'decision' => 'denied',
        ]);
    }

    public function test_only_lists_actions_for_the_active_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $mine = PendingAction::factory()->create([
            'project_id' => $project->id,
            'status' => ActionStatus::Pending,
            'command' => 'ANALYZE TABLE mine',
        ]);
        $theirs = PendingAction::factory()->create([
            'project_id' => $other->id,
            'status' => ActionStatus::Pending,
            'command' => 'ANALYZE TABLE theirs',
        ]);

        Livewire::test(ApprovalQueue::class)
            ->assertSee('ANALYZE TABLE mine')
            ->assertDontSee('ANALYZE TABLE theirs')
            ->assertViewHas('actions', fn ($actions) => $actions->pluck('id')->all() === [$mine->id]);
    }

    public function test_cannot_decide_an_action_from_another_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $theirs = PendingAction::factory()->create([
            'project_id' => $other->id,
            'status' => ActionStatus::Pending,
        ]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ApprovalQueue::class)->call('deny', $theirs->id);
    }

    public function test_decisions_are_written_back_to_the_originating_chat(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);
        $session = ChatSession::factory()->for($project)->create();

        $action = PendingAction::factory()->create([
            'project_id' => $project->id,
            'chat_session_id' => $session->id,
            'status' => ActionStatus::Pending,
            'command' => 'DROP TABLE events',
        ]);

        Livewire::test(ApprovalQueue::class)->call('deny', $action->id);

        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'tool',
        ]);
    }

    public function test_stale_actions_expire_and_disappear_from_the_queue(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $stale = PendingAction::factory()->create([
            'project_id' => $project->id,
            'status' => ActionStatus::Pending,
            'proposed_at' => now()->subHours(PendingAction::EXPIRES_AFTER_HOURS + 1),
        ]);

        Livewire::test(ApprovalQueue::class)
            ->assertDontSee($stale->id);

        $this->assertSame(ActionStatus::Expired, $stale->fresh()->status);
    }
}
