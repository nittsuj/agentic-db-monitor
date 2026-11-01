<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ChatRole;
use App\Enums\ToolExecutionStatus;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Project;
use App\Models\ToolExecutionLog;
use App\Models\User;
use App\Services\Ai\AgentRegistry;
use Faker\Factory as Faker;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Related dummy data for the chat schema, built with model factories:
 * user → chat sessions → chat messages → tool execution logs → agents.
 *
 * For the demo admin and each demo project it adds one past conversation of
 * 6 questions and 6 replies, each reply with 3 tool logs spread over the three
 * agents. Run after DemoSeeder; running it again adds nothing.
 */
class ChatHistorySeeder extends Seeder
{
    public const TITLE = 'Contoh riwayat diagnostik';

    public const TURNS = 6;

    public const LOGS_PER_REPLY = 3;

    /** The projects DemoSeeder creates. */
    private const DEMO_PROJECTS = ['Local MySQL Playground', 'Local Postgres Playground'];

    public function run(): void
    {
        // Model factories need Faker, a dev dependency. The Docker image is built
        // without dev packages and runs db:seed when SEED_DEMO=true, so there
        // this seeder steps aside instead of stopping the container.
        if (! class_exists(Faker::class)) {
            $this->command?->warn('ChatHistorySeeder skipped: Faker is not installed (production build).');

            return;
        }

        $user = User::where('email', 'admin@example.com')->first();

        if ($user === null) {
            $this->command?->warn('ChatHistorySeeder: run DemoSeeder first (no demo user).');

            return;
        }

        $agents = array_map(
            fn (string $class) => $class::where('slug', $class::SLUG)->sole(),
            AgentRegistry::AGENTS,
        );

        foreach (Project::whereIn('name', self::DEMO_PROJECTS)->orderBy('name')->get() as $project) {
            $exists = ChatSession::whereBelongsTo($user)->whereBelongsTo($project)->where('title', self::TITLE)->exists();

            if ($exists) {
                continue;
            }

            $this->seedConversation($user, $project, $agents);
        }
    }

    /**
     * @param  list<Model>  $agents
     */
    private function seedConversation(User $user, Project $project, array $agents): void
    {
        // In the past, so the chat page keeps opening DemoSeeder's own,
        // newer "Playground chat"; this one is found through the history.
        $start = now()->subDays(3)->startOfHour();

        $session = ChatSession::factory()
            ->for($user)
            ->for($project)
            ->create(['title' => self::TITLE, 'created_at' => $start, 'updated_at' => $start]);

        for ($turn = 0; $turn < self::TURNS; $turn++) {
            $askedAt = $start->copy()->addMinutes($turn * 5);

            ChatMessage::factory()->for($session)->create([
                'role' => ChatRole::User,
                'content' => "Pertanyaan {$turn}: ".fake()->sentence(),
                'created_at' => $askedAt,
                'updated_at' => $askedAt,
            ]);

            // Only replies have tool logs: the assistant runs tools, the user does not.
            ChatMessage::factory()
                ->for($session)
                ->has(
                    ToolExecutionLog::factory()
                        ->count(self::LOGS_PER_REPLY)
                        ->sequence(function (Sequence $sequence) use ($agents, $turn, $askedAt): array {
                            $agent = $agents[($turn * self::LOGS_PER_REPLY + $sequence->index) % count($agents)];
                            $failed = ($turn * self::LOGS_PER_REPLY + $sequence->index) % 7 === 6;
                            $ranAt = $askedAt->copy()->addSeconds(10 + $sequence->index * 5);

                            return [
                                'loggable_type' => $agent->getMorphClass(),
                                'loggable_id' => $agent->getKey(),
                                'tool_name' => fake()->randomElement($agent->handles),
                                'status' => $failed ? ToolExecutionStatus::Error : ToolExecutionStatus::Success,
                                'result' => $failed ? null : '{"rows":'.fake()->numberBetween(1, 40).'}',
                                'error' => $failed ? 'Connection to the target database timed out.' : null,
                                'created_at' => $ranAt,
                                'updated_at' => $ranAt,
                            ];
                        }),
                    'toolExecutionLogs',
                )
                ->create([
                    'role' => ChatRole::Assistant,
                    'content' => "Jawaban {$turn}: ".fake()->paragraph(),
                    'created_at' => $askedAt->copy()->addMinute(),
                    'updated_at' => $askedAt->copy()->addMinute(),
                ]);
        }
    }
}
