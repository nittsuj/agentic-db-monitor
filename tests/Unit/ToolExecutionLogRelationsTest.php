<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ToolExecutionStatus;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\Project;
use App\Models\QueryDiagnostician;
use App\Models\ToolExecutionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ToolExecutionLogRelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function agent(string $class): QueryDiagnostician|MetricAnalyzer|MutationPlanner
    {
        return $class::where('slug', $class::SLUG)->sole();
    }

    public function test_a_message_has_many_logs_and_each_belongs_to_its_message(): void
    {
        $message = ChatMessage::factory()->create();
        $logs = ToolExecutionLog::factory()->count(3)->for($message)->create();

        $this->assertEqualsCanonicalizing($logs->pluck('id')->all(), $message->toolExecutionLogs->pluck('id')->all());
        $this->assertTrue($logs->first()->chatMessage->is($message));
    }

    public function test_a_log_points_to_the_agent_that_ran_it_and_each_agent_has_its_logs(): void
    {
        $diagnostician = $this->agent(QueryDiagnostician::class);
        $analyzer = $this->agent(MetricAnalyzer::class);
        $planner = $this->agent(MutationPlanner::class);

        $byDiagnostician = ToolExecutionLog::factory()->count(2)->forAgent($diagnostician)->create();
        $byAnalyzer = ToolExecutionLog::factory()->forAgent($analyzer)->create();
        $byPlanner = ToolExecutionLog::factory()->forAgent($planner)->create();

        $this->assertTrue($byAnalyzer->loggable->is($analyzer));
        $this->assertInstanceOf(MutationPlanner::class, $byPlanner->fresh()->loggable);
        $this->assertSame('propose_mutation', $byPlanner->tool_name);

        $this->assertEqualsCanonicalizing($byDiagnostician->pluck('id')->all(), $diagnostician->toolExecutionLogs->pluck('id')->all());
        $this->assertCount(1, $analyzer->toolExecutionLogs);
        $this->assertCount(1, $planner->toolExecutionLogs);
    }

    public function test_the_short_morph_name_is_stored(): void
    {
        $log = ToolExecutionLog::factory()->forAgent($this->agent(MetricAnalyzer::class))->create();

        $this->assertSame('metric_analyzer', DB::table('tool_execution_logs')->where('id', $log->id)->value('loggable_type'));
    }

    public function test_status_arguments_and_error_are_stored(): void
    {
        $ok = ToolExecutionLog::factory()->success()->create(['arguments' => ['table' => 'orders']]);
        $failed = ToolExecutionLog::factory()->error()->create();

        $this->assertSame(ToolExecutionStatus::Success, $ok->fresh()->status);
        $this->assertSame(['table' => 'orders'], $ok->fresh()->arguments);
        $this->assertSame(ToolExecutionStatus::Error, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->result);
        $this->assertNotNull($failed->fresh()->error);
    }

    public function test_a_very_long_result_is_cut(): void
    {
        $log = ToolExecutionLog::factory()->create(['result' => str_repeat('x', 50_000)]);

        $stored = $log->fresh()->result;

        $this->assertLessThanOrEqual(ToolExecutionLog::MAX_RESULT_LENGTH + 20, mb_strlen($stored));
        $this->assertStringEndsWith('[truncated]', $stored);
    }

    public function test_deleting_the_message_deletes_its_logs(): void
    {
        $log = ToolExecutionLog::factory()->create();
        $other = ToolExecutionLog::factory()->create();

        $log->chatMessage->delete();

        $this->assertModelMissing($log);
        $this->assertModelExists($other);
    }

    public function test_deleting_the_session_user_or_project_deletes_the_logs_down_the_chain(): void
    {
        $make = function (): array {
            $user = User::factory()->create();
            $project = Project::factory()->create();
            $session = ChatSession::factory()->for($user)->for($project)->create();
            $log = ToolExecutionLog::factory()->for(ChatMessage::factory()->for($session))->create();

            return [$user, $project, $session, $log];
        };

        [, , $session, $log] = $make();
        $session->delete();
        $this->assertModelMissing($log);

        [$user, , , $log] = $make();
        $user->delete();
        $this->assertModelMissing($log);

        [, $project, , $log] = $make();
        $project->delete();
        $this->assertModelMissing($log);
    }

    public function test_deleting_logs_leaves_the_agents_alone(): void
    {
        $agent = $this->agent(QueryDiagnostician::class);
        ToolExecutionLog::factory()->forAgent($agent)->create()->chatMessage->delete();

        $this->assertModelExists($agent);
    }
}
