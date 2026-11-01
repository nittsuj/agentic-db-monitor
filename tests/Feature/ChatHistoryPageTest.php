<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRole;
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

class ChatHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * A session of the user with $messages assistant replies, each with
     * $logsPerMessage tool logs spread over all three agents.
     */
    protected function conversation(int $messages, int $logsPerMessage, ?User $owner = null): ChatSession
    {
        $agents = [
            QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->sole(),
            MetricAnalyzer::where('slug', MetricAnalyzer::SLUG)->sole(),
            MutationPlanner::where('slug', MutationPlanner::SLUG)->sole(),
        ];

        $session = ChatSession::factory()->for($owner ?? $this->user)->create();

        for ($m = 0; $m < $messages; $m++) {
            $message = ChatMessage::factory()->for($session)->create(['role' => ChatRole::Assistant, 'content' => "Reply {$m}"]);

            for ($l = 0; $l < $logsPerMessage; $l++) {
                ToolExecutionLog::factory()->for($message)->forAgent($agents[($m * $logsPerMessage + $l) % 3])->create();
            }
        }

        return $session;
    }

    public function test_guests_are_sent_to_login(): void
    {
        $session = $this->conversation(1, 1);

        $this->get(route('chat.history'))->assertRedirect(route('login'));
        $this->get(route('chat.history.show', $session))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_only_the_users_own_conversations(): void
    {
        $mine = $this->conversation(2, 0);
        $mine->project->update(['name' => 'My Warehouse']);
        $theirs = $this->conversation(1, 0, User::factory()->create());
        $theirs->project->update(['name' => 'Someone Elses DB']);

        // The sidebar's project picker lists every project, so check the
        // conversations the page was given and the links it shows.
        $this->actingAs($this->user)
            ->get(route('chat.history'))
            ->assertOk()
            ->assertViewHas('sessions', fn ($sessions): bool => $sessions->pluck('id')->all() === [$mine->id])
            ->assertSeeInOrder(['My Warehouse', '2', route('chat.history.show', $mine)])
            ->assertDontSee(route('chat.history.show', $theirs));
    }

    public function test_a_conversation_shows_its_messages_and_every_tool_log(): void
    {
        $session = ChatSession::factory()->for($this->user)->create();
        ChatMessage::factory()->for($session)->create(['role' => ChatRole::User, 'content' => 'Why is checkout slow?', 'created_at' => now()->subMinute()]);
        $reply = ChatMessage::factory()->for($session)->create(['role' => ChatRole::Assistant, 'content' => 'The **orders** table is bloated.']);

        $diagnostician = QueryDiagnostician::where('slug', QueryDiagnostician::SLUG)->sole();
        ToolExecutionLog::factory()->for($reply)->forAgent($diagnostician)->create([
            'tool_name' => 'analyze_table_bloat', 'duration_ms' => 42, 'arguments' => [], 'result' => '{"orders":"38% dead rows"}',
        ]);
        ToolExecutionLog::factory()->for($reply)->forAgent($diagnostician)->error()->create(['tool_name' => 'get_slow_queries']);

        $this->actingAs($this->user)
            ->get(route('chat.history.show', $session))
            ->assertOk()
            ->assertSee('Why is checkout slow?')
            ->assertSee('<strong>orders</strong>', false)
            ->assertSeeInOrder(['Query Diagnostician', 'analyze_table_bloat', 'success', '42 ms'])
            ->assertSee('38% dead rows')
            ->assertSeeInOrder(['get_slow_queries', 'error'])
            ->assertSee('Connection to the target database timed out.');
    }

    public function test_another_users_conversation_is_not_found(): void
    {
        $theirs = $this->conversation(1, 1, User::factory()->create());

        $this->actingAs($this->user)->get(route('chat.history.show', $theirs))->assertNotFound();
    }

    public function test_a_reply_that_only_called_tools_is_shown_with_its_logs(): void
    {
        $session = ChatSession::factory()->for($this->user)->create();
        $reply = ChatMessage::factory()->for($session)->create(['role' => ChatRole::Assistant, 'content' => '']);
        ToolExecutionLog::factory()->for($reply)->create(['tool_name' => 'list_tables']);

        $this->actingAs($this->user)
            ->get(route('chat.history.show', $session))
            ->assertOk()
            ->assertSee('Hanya memanggil alat, tanpa teks.')
            ->assertSee('list_tables');
    }

    public function test_html_in_a_reply_is_not_rendered(): void
    {
        $session = ChatSession::factory()->for($this->user)->create();
        ChatMessage::factory()->for($session)->create(['role' => ChatRole::Assistant, 'content' => '<script>alert(1)</script>']);

        $this->actingAs($this->user)
            ->get(route('chat.history.show', $session))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    /**
     * The whole page, layout included, runs the same number of queries for a
     * short and a long conversation: the history adds no query per row.
     */
    public function test_the_page_runs_the_same_number_of_queries_however_long_the_conversation(): void
    {
        $short = $this->conversation(messages: 3, logsPerMessage: 1);
        $long = $this->conversation(messages: 30, logsPerMessage: 3);
        $this->actingAs($this->user);

        $count = function (ChatSession $session): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('chat.history.show', $session))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count($short), $count($long));
    }

    public function test_the_chat_page_links_to_the_history(): void
    {
        // The link sits in the layout's actions slot, so render the whole page.
        $this->actingAs($this->user)
            ->get(route('chat'))
            ->assertOk()
            ->assertSee('href="'.route('chat.history').'"', false)
            ->assertSee('Riwayat');
    }
}
