<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ChatSession;
use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\Project;
use App\Models\QueryDiagnostician;
use App\Services\Ai\AgentRegistry;
use App\Services\Ai\ProposeMutationTool;
use App\Services\Database\DiagnosticTools;
use App\Services\Monitoring\MetricSummaryTool;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Tool;
use RuntimeException;
use Tests\TestCase;

class AgentRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_agent_has_its_own_row_after_migrating(): void
    {
        $this->assertSame(
            ['list_tables', 'get_table_schema', 'get_slow_queries', 'check_connection_pool', 'analyze_table_bloat'],
            QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->sole()->handles,
        );
        $this->assertSame(['get_metric_summary'], MetricAnalyzer::where('slug', MetricAnalyzer::SLUG)->sole()->handles);
        $this->assertSame(['propose_mutation'], MutationPlanner::where('slug', MutationPlanner::SLUG)->sole()->handles);
    }

    public function test_maps_each_tool_to_the_agent_that_handles_it(): void
    {
        $registry = new AgentRegistry;

        foreach (['list_tables', 'get_table_schema', 'get_slow_queries', 'check_connection_pool', 'analyze_table_bloat'] as $tool) {
            $this->assertTrue(QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->sole()->is($registry->forTool($tool)), $tool);
        }

        $this->assertInstanceOf(MetricAnalyzer::class, $registry->forTool('get_metric_summary'));
        $this->assertInstanceOf(MutationPlanner::class, $registry->forTool('propose_mutation'));
    }

    /**
     * Built from the chat's real tool definitions, so a tool added later
     * without an agent fails here instead of in a chat.
     */
    public function test_every_tool_the_chat_offers_has_an_agent(): void
    {
        $project = Project::factory()->create();
        $session = ChatSession::factory()->for($project)->create();

        $tools = [
            ...DiagnosticTools::for(DB::connection(), DiagnosticTools::driverFor('mysql')),
            ProposeMutationTool::make($project, $session->id),
            MetricSummaryTool::make($project->id),
        ];
        $names = array_map(fn (Tool $tool): string => $tool->name(), $tools);

        $this->assertCount(7, $names);

        $registry = new AgentRegistry;

        foreach ($names as $name) {
            $this->assertNotNull($registry->forTool($name), $name);
        }
    }

    public function test_extra_agent_rows_do_not_take_over_a_tool(): void
    {
        QueryDiagnostician::factory()->create();

        $agent = (new AgentRegistry)->forTool('list_tables');

        $this->assertSame(QueryDiagnostician::SLUG, $agent->slug);
    }

    public function test_an_unknown_tool_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No agent handles the chat tool 'drop_everything'");

        (new AgentRegistry)->forTool('drop_everything');
    }

    public function test_reads_the_agents_once_per_registry(): void
    {
        $registry = new AgentRegistry;
        $registry->forTool('list_tables');

        DB::enableQueryLog();
        $registry->forTool('get_metric_summary');
        $registry->forTool('propose_mutation');

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_app_shares_one_registry_per_request(): void
    {
        $this->assertSame(app(AgentRegistry::class), app(AgentRegistry::class));
    }

    public function test_handling_scope_and_handles_tool(): void
    {
        $this->assertSame([QueryDiagnostician::SLUG], QueryDiagnostician::handling('get_slow_queries')->pluck('slug')->all());
        $this->assertSame([], MetricAnalyzer::handling('get_slow_queries')->pluck('slug')->all());

        $this->assertTrue(MutationPlanner::where('slug', MutationPlanner::SLUG)->sole()->handlesTool('propose_mutation'));
        $this->assertFalse(MutationPlanner::where('slug', MutationPlanner::SLUG)->sole()->handlesTool('list_tables'));
    }

    public function test_agents_use_short_morph_names(): void
    {
        $this->assertSame('query_diagnostician', (new QueryDiagnostician)->getMorphClass());
        $this->assertSame('metric_analyzer', (new MetricAnalyzer)->getMorphClass());
        $this->assertSame('mutation_planner', (new MutationPlanner)->getMorphClass());
        $this->assertSame(MutationPlanner::class, Relation::getMorphedModel('mutation_planner'));
    }
}
