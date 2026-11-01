<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Livewire\Chat\ChatWindow;
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
use Livewire\Livewire;
use Tests\TestCase;

class ChatHistoryQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A conversation of $messages assistant replies, each with $logsPerMessage
     * tool logs, spread over all three agents.
     */
    protected function conversation(int $messages, int $logsPerMessage): ChatSession
    {
        $agents = [
            QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->sole(),
            MetricAnalyzer::where('slug', MetricAnalyzer::SLUG)->sole(),
            MutationPlanner::where('slug', MutationPlanner::SLUG)->sole(),
        ];

        $session = ChatSession::factory()->create();

        for ($m = 0; $m < $messages; $m++) {
            $message = ChatMessage::factory()->for($session)->create(['role' => ChatRole::Assistant]);

            for ($l = 0; $l < $logsPerMessage; $l++) {
                ToolExecutionLog::factory()->for($message)->forAgent($agents[($m * $logsPerMessage + $l) % 3])->create();
            }
        }

        return $session;
    }

    /**
     * Load the session with its full history and read every log's agent, the
     * way a page that shows the history does. Returns the number of queries.
     */
    protected function queriesToReadEverything(ChatSession $session): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $loaded = ChatSession::withFullHistory()->findOrFail($session->id);

        foreach ($loaded->messages as $message) {
            foreach ($message->toolExecutionLogs as $log) {
                $log->loggable->name;
            }
        }

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_number_of_queries_does_not_grow_with_the_conversation(): void
    {
        $short = $this->conversation(messages: 3, logsPerMessage: 1);
        $long = $this->conversation(messages: 30, logsPerMessage: 3);

        $this->assertSame(3, ToolExecutionLog::whereIn('chat_message_id', $short->messages()->pluck('id'))->count());
        $this->assertSame(90, ToolExecutionLog::whereIn('chat_message_id', $long->messages()->pluck('id'))->count());

        // Session + messages + logs + one query per agent type.
        $this->assertSame(6, $this->queriesToReadEverything($short));
        $this->assertSame(6, $this->queriesToReadEverything($long));
    }

    public function test_fewer_agent_types_means_fewer_queries_never_more(): void
    {
        $oneAgent = $this->conversation(messages: 1, logsPerMessage: 1);
        $noLogs = ChatSession::factory()->has(ChatMessage::factory()->count(5), 'messages')->create();

        $this->assertSame(4, $this->queriesToReadEverything($oneAgent));
        $this->assertSame(3, $this->queriesToReadEverything($noLogs));
    }

    public function test_messages_and_logs_come_back_in_order(): void
    {
        $session = ChatSession::factory()->create();
        $first = ChatMessage::factory()->for($session)->create(['created_at' => now()->subMinutes(2)]);
        $second = ChatMessage::factory()->for($session)->create(['created_at' => now()->subMinute()]);
        $later = ToolExecutionLog::factory()->for($first)->create(['created_at' => now()->subSeconds(10)]);
        $earlier = ToolExecutionLog::factory()->for($first)->create(['created_at' => now()->subSeconds(20)]);

        $loaded = ChatSession::withFullHistory()->findOrFail($session->id);

        $this->assertSame([$first->id, $second->id], $loaded->messages->pluck('id')->all());
        $this->assertSame([$earlier->id, $later->id], $loaded->messages->first()->toolExecutionLogs->pluck('id')->all());
    }

    public function test_the_chat_page_loads_a_long_history_in_the_same_number_of_queries(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        $session = $this->conversation(messages: 30, logsPerMessage: 3);
        $session->update(['user_id' => $user->id, 'project_id' => $project->id]);

        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component = Livewire::test(ChatWindow::class);
        $historyQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/from "(chat_messages|tool_execution_logs|query_diagnosticians|metric_analyzers|mutation_planners)"/', $sql) === 1)
            ->count();
        DB::disableQueryLog();

        $this->assertCount(30, $component->get('messages'));
        // messages + logs + 3 agent types, not one per message or per log.
        $this->assertSame(5, $historyQueries);
    }
}
