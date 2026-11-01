<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Enums\ToolExecutionStatus;
use App\Livewire\Chat\ChatWindow;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\MetricAnalyzer;
use App\Models\MutationPlanner;
use App\Models\Project;
use App\Models\QueryDiagnostician;
use App\Models\ToolExecutionLog;
use App\Models\User;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Tests\TestCase;

class ChatToolLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        // Nothing listens on the factory's 127.0.0.1:3306, so tools that query
        // the target database fail for real.
        $this->project = Project::factory()->create();
        session(['active_project_id' => $this->project->id]);
    }

    /**
     * A provider that does what a real one does with tool calls: it runs the
     * requested tools through Prism's own call path, then streams its text.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $toolCalls  [name, arguments]
     * @return object{requests: list<TextRequest>}
     */
    protected function providerCalling(array $toolCalls, string $reply): object
    {
        $manager = new class($toolCalls, $reply) extends PrismManager
        {
            /** @var list<TextRequest> */
            public array $requests = [];

            /**
             * @param  list<array{0: string, 1: array<string, mixed>}>  $toolCalls
             */
            public function __construct(public array $toolCalls, public string $reply) {}

            public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
            {
                return new class($this) extends Provider
                {
                    public function __construct(private readonly object $manager) {}

                    public function stream(TextRequest $request): Generator
                    {
                        $this->manager->requests[] = $request;

                        $tools = collect($request->tools())->keyBy(fn (Tool $tool): string => $tool->name());

                        foreach ($this->manager->toolCalls as [$name, $arguments]) {
                            call_user_func_array($tools[$name]->handle(...), $arguments);
                        }

                        if ($this->manager->reply !== '') {
                            yield new TextDeltaEvent(
                                id: EventID::generate(),
                                timestamp: time(),
                                delta: $this->manager->reply,
                                messageId: EventID::generate(),
                            );
                        }
                    }
                };
            }
        };

        app()->instance(PrismManager::class, $manager);

        return $manager;
    }

    protected function send(string $text): void
    {
        Livewire::test(ChatWindow::class)
            ->set('input', $text)
            ->call('send')
            ->assertHasNoErrors();
    }

    protected function assistantReply(): ChatMessage
    {
        return ChatMessage::where('role', ChatRole::Assistant)->latest()->firstOrFail();
    }

    public function test_each_tool_call_is_logged_on_the_reply_with_its_agent(): void
    {
        $this->providerCalling([
            ['get_metric_summary', ['window_minutes' => 30]],
            ['propose_mutation', ['command' => 'DELETE FROM sessions WHERE id = 1']],
        ], 'I queued the delete for your approval.');

        $this->send('Clean up session 1');

        $reply = $this->assistantReply();
        $logs = $reply->toolExecutionLogs()->orderBy('created_at')->get();

        $this->assertSame('I queued the delete for your approval.', $reply->content);
        $this->assertSame(['get_metric_summary', 'propose_mutation'], $logs->pluck('tool_name')->all());

        [$summary, $proposal] = $logs;
        $this->assertInstanceOf(MetricAnalyzer::class, $summary->loggable);
        $this->assertSame(['window_minutes' => 30], $summary->arguments);
        $this->assertInstanceOf(MutationPlanner::class, $proposal->loggable);
        $this->assertSame(['command' => 'DELETE FROM sessions WHERE id = 1'], $proposal->arguments);

        foreach ($logs as $log) {
            $this->assertSame(ToolExecutionStatus::Success, $log->status);
            $this->assertNotNull($log->result);
            $this->assertIsInt($log->duration_ms);
            $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        }
    }

    public function test_a_failing_tool_is_logged_as_an_error(): void
    {
        $this->providerCalling([['list_tables', []]], 'I could not reach the database.');

        $this->send('Which tables are there?');

        $log = $this->assistantReply()->toolExecutionLogs()->sole();

        $this->assertSame('list_tables', $log->tool_name);
        $this->assertInstanceOf(QueryDiagnostician::class, $log->loggable);
        $this->assertSame(ToolExecutionStatus::Error, $log->status);
        $this->assertNull($log->result);
        $this->assertNotEmpty($log->error);
    }

    public function test_a_reply_that_only_called_tools_still_keeps_its_logs_but_shows_no_empty_bubble(): void
    {
        $this->providerCalling([['get_metric_summary', ['window_minutes' => 15]]], '');

        $this->send('Summarise the metrics');

        $reply = $this->assistantReply();
        $this->assertSame('', $reply->content);
        $this->assertSame(1, $reply->toolExecutionLogs()->count());

        // Reopening the chat shows the user's message only.
        $messages = Livewire::test(ChatWindow::class)->get('messages');
        $this->assertSame(['user'], array_column($messages, 'role'));

        // And the empty reply is not sent back to the model as history.
        $manager = $this->providerCalling([], 'Here is a follow-up.');
        $this->send('And now?');
        $history = $manager->requests[0]->messages();
        $this->assertCount(2, $history);
    }

    public function test_a_reply_without_tool_calls_logs_nothing(): void
    {
        $this->providerCalling([], 'Everything looks fine.');

        $this->send('How are things?');

        $this->assertSame('Everything looks fine.', $this->assistantReply()->content);
        $this->assertSame(0, ToolExecutionLog::count());
    }

    public function test_logs_belong_to_the_senders_session(): void
    {
        $this->providerCalling([['get_metric_summary', ['window_minutes' => 30]]], 'Done.');

        $this->send('Summarise');

        $session = ChatSession::where('user_id', auth()->id())->sole();
        $this->assertTrue($this->assistantReply()->chatSession->is($session));
        $this->assertSame(1, ToolExecutionLog::count());
    }
}
