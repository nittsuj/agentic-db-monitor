<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Livewire\Chat\ChatWindow;
use App\Livewire\Shell\Sidebar;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\Project;
use App\Models\QueryDiagnostician;
use App\Models\ToolExecutionLog;
use App\Models\User;
use Database\Seeders\ChatHistorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function historySessions()
    {
        return ChatSession::where('title', ChatHistorySeeder::TITLE);
    }

    public function test_seeding_builds_a_related_history_for_each_demo_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->sole();
        $sessions = $this->historySessions()->with(['user', 'project', 'messages.toolExecutionLogs.loggable'])->get();

        $this->assertSame(['Local MySQL Playground', 'Local Postgres Playground'], $sessions->pluck('project.name')->sort()->values()->all());

        foreach ($sessions as $session) {
            $this->assertTrue($session->user->is($admin));
            $this->assertCount(ChatHistorySeeder::TURNS * 2, $session->messages);

            $replies = $session->messages->where('role', ChatRole::Assistant);
            $questions = $session->messages->where('role', ChatRole::User);
            $this->assertCount(ChatHistorySeeder::TURNS, $replies);

            foreach ($replies as $reply) {
                $this->assertCount(ChatHistorySeeder::LOGS_PER_REPLY, $reply->toolExecutionLogs);
            }

            foreach ($questions as $question) {
                $this->assertCount(0, $question->toolExecutionLogs, 'A user message never has tool logs.');
            }

            $agentTypes = $session->messages->flatMap->toolExecutionLogs->map(fn (ToolExecutionLog $log) => $log->loggable::class)->unique()->sort()->values()->all();
            $this->assertSame([MetricAnalyzer::class, MutationPlanner::class, QueryDiagnostician::class], $agentTypes);
        }
    }

    public function test_every_log_points_to_an_existing_message_and_agent_and_its_tool_belongs_to_that_agent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $logs = ToolExecutionLog::with(['chatMessage', 'loggable'])->get();

        $this->assertSame(2 * ChatHistorySeeder::TURNS * ChatHistorySeeder::LOGS_PER_REPLY, $logs->count());

        foreach ($logs as $log) {
            $this->assertNotNull($log->chatMessage);
            $this->assertNotNull($log->loggable);
            $this->assertTrue($log->loggable->handlesTool($log->tool_name), "{$log->tool_name} is not handled by ".$log->loggable::class);
        }

        $this->assertTrue($logs->contains(fn (ToolExecutionLog $log) => $log->status->value === 'error'), 'The seed includes failed calls too.');
    }

    public function test_seeding_again_adds_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [ChatSession::count(), ChatMessage::count(), ToolExecutionLog::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [ChatSession::count(), ChatMessage::count(), ToolExecutionLog::count()]);
    }

    public function test_the_chat_page_still_opens_the_demo_conversation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@example.com')->sole());
        session(['active_project_id' => Project::where('name', 'Local MySQL Playground')->value('id')]);

        $sessionId = Livewire::test(ChatWindow::class)->get('chatSessionId');

        $this->assertSame('Playground chat', ChatSession::find($sessionId)->title);
    }

    public function test_the_seeded_history_can_be_opened_on_the_history_page(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@example.com')->sole());

        $session = $this->historySessions()->firstOrFail();

        $this->get(route('chat.history'))->assertOk()->assertSee(route('chat.history.show', $session));
        $this->get(route('chat.history.show', $session))->assertOk()->assertSee('Query Diagnostician');
    }

    public function test_resetting_the_demo_data_does_not_add_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = ToolExecutionLog::count();

        Livewire::test(Sidebar::class)->call('resetDemoData');

        $this->assertSame($before, ToolExecutionLog::count());
    }

    public function test_without_the_demo_user_it_does_nothing(): void
    {
        $this->seed(ChatHistorySeeder::class);

        $this->assertSame(0, ChatSession::count());
    }
}
