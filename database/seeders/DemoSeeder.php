<?php

namespace Database\Seeders;

use App\Models\User;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedUser($now);

        $mysqlId = $this->seedProject('Local MySQL Playground', 'mysql', '127.0.0.1', 3306, 'playground', 'root', $now);
        $pgsqlId = $this->seedProject('Local Postgres Playground', 'pgsql', '127.0.0.1', 5432, 'playground_pg', 'postgres', $now);

        $this->seedAiSetting($now);

        $mysqlSessionId = $this->seedChatSession($mysqlId, $now);
        $pgsqlSessionId = $this->seedChatSession($pgsqlId, $now);

        $this->seedChatMessages($mysqlSessionId, $now);
        $this->seedChatMessages($pgsqlSessionId, $now);

        $this->seedPendingActions($mysqlId, $mysqlSessionId, $now);

        $this->seedActionLogs($mysqlId, 'Local MySQL Playground', $mysqlSessionId, $now);

        $this->seedMetricSamples([$mysqlId, $pgsqlId]);

        $this->seedThresholds($mysqlId, $now);
        $this->seedThresholds($pgsqlId, $now);

        $this->command->info('Demo data seeded.');
    }

    private function seedUser($now): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Admin', 'password' => Hash::make('password'), 'email_verified_at' => $now]
        );
    }

    private function seedProject(string $name, string $driver, string $host, int $port, string $database, string $username, $now): string
    {
        return $this->ensureRow('projects', [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
        ], [
            'name' => $name,
            'password' => Crypt::encryptString('secret'),
            'options' => json_encode(['source' => 'demo-seeder']),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedAiSetting($now): void
    {
        $this->ensureRow('ai_settings', [
            'provider' => 'ollama',
            'model' => 'llama3.1',
        ], [
            'base_url' => 'https://ollama.com',
            'api_key' => 'ollama-demo-key',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedChatSession(string $projectId, $now): string
    {
        return $this->ensureRow('chat_sessions', [
            'project_id' => $projectId,
            'title' => 'Playground chat',
        ], [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedChatMessages(string $sessionId, $now): void
    {
        $this->ensureEmpty('chat_messages', ['chat_session_id' => $sessionId], function () use ($sessionId, $now) {
            $messages = [
                ['role' => 'user', 'content' => 'Is my connection pool healthy right now?', 'meta' => null],
                ['role' => 'assistant', 'content' => "I'll check the current connection pool utilization.", 'meta' => null],
                ['role' => 'tool', 'content' => '{"pool_used": 71, "pool_total": 100, "pool_utilization_pct": 71.0}', 'meta' => json_encode(['tool' => 'check_connection_pool'])],
                ['role' => 'assistant', 'content' => 'Your pool is at 71% — below the 80% threshold, so nothing needs attention right now.', 'meta' => null],
            ];

            foreach ($messages as $i => $message) {
                $at = $now->copy()->subMinutes((count($messages) - 1 - $i) * 2);

                DB::table('chat_messages')->insert(array_merge([
                    'id' => Str::uuid7(),
                    'chat_session_id' => $sessionId,
                    'created_at' => $at,
                    'updated_at' => $at,
                ], $message));
            }
        });
    }

    private function seedPendingActions(string $projectId, string $sessionId, $now): void
    {
        $this->ensureEmpty('pending_actions', ['project_id' => $projectId], function () use ($projectId, $sessionId, $now) {
            DB::table('pending_actions')->insert([
                'id' => Str::uuid7(),
                'project_id' => $projectId,
                'chat_session_id' => $sessionId,
                'engine' => 'mysql',
                'category' => 'maintenance',
                'command' => 'OPTIMIZE TABLE events;',
                'status' => 'pending',
                'meta' => json_encode(['source' => 'demo-seeder']),
                'proposed_at' => $now,
                'decided_at' => null,
                'executed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('pending_actions')->insert([
                'id' => Str::uuid7(),
                'project_id' => $projectId,
                'chat_session_id' => $sessionId,
                'engine' => 'mysql',
                'category' => 'maintenance',
                'command' => 'ANALYZE TABLE events;',
                'status' => 'executed',
                'meta' => json_encode(['source' => 'demo-seeder']),
                'proposed_at' => $now->copy()->subHours(2),
                'decided_at' => $now->copy()->subHours(1),
                'executed_at' => $now->copy()->subMinutes(55),
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subMinutes(55),
            ]);
        });
    }

    private function seedActionLogs(string $projectId, string $projectName, string $sessionId, $now): void
    {
        $this->ensureEmpty('action_logs', ['project_id' => $projectId], function () use ($projectId, $projectName, $sessionId, $now) {
            $logs = [
                [
                    'pending_action_id' => null,
                    'chat_session_id' => $sessionId,
                    'decision' => 'approved',
                    'command' => 'ANALYZE TABLE events;',
                    'result' => 'OK',
                    'error' => null,
                    'executed_at' => $now->copy()->subMinutes(55),
                    'created_at' => $now->copy()->subMinutes(56),
                ],
                [
                    'pending_action_id' => null,
                    'chat_session_id' => $sessionId,
                    'decision' => 'denied',
                    'command' => 'DROP TABLE old_archive;',
                    'result' => null,
                    'error' => null,
                    'executed_at' => null,
                    'created_at' => $now->copy()->subHours(5),
                ],
                [
                    'pending_action_id' => null,
                    'chat_session_id' => $sessionId,
                    'decision' => 'failed',
                    'command' => 'VACUUM FULL;',
                    'result' => null,
                    'error' => 'permission denied for table',
                    'executed_at' => $now->copy()->subDays(1),
                    'created_at' => $now->copy()->subDays(1),
                ],
                [
                    'pending_action_id' => null,
                    'chat_session_id' => null,
                    'decision' => 'executed',
                    'command' => 'OPTIMIZE TABLE events;',
                    'result' => 'OK',
                    'error' => null,
                    'executed_at' => $now->copy()->subHours(26),
                    'created_at' => $now->copy()->subHours(26),
                ],
            ];

            foreach ($logs as $log) {
                DB::table('action_logs')->insert(array_merge([
                    'id' => Str::uuid7(),
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                    'engine' => 'mysql',
                    'actor' => 'user',
                    'updated_at' => $now,
                ], $log));
            }
        });
    }

    private function seedMetricSamples(array $projectIds): void
    {
        $keys = ['connection_pool_used', 'slow_queries_per_min'];

        foreach ($projectIds as $projectId) {
            $projectCreatedAt = DB::table('projects')->where('id', $projectId)->value('created_at');
            $start = Carbon::parse($projectCreatedAt)->subHours(48);

            foreach ($keys as $key) {
                for ($i = 0; $i < 96; $i++) {
                    $capturedAt = $start->copy()->addMinutes($i * 30);

                    $value = match ($key) {
                        'connection_pool_used' => round(45 + 30 * sin($i / 9) + ($i % 23 === 0 ? 35 : 0), 1),
                        'slow_queries_per_min' => round(max(0, 4 + 3 * sin($i / 7) + ($i % 17 === 0 ? 6 : 0)), 1),
                        default => 0,
                    };

                    $this->ensureRow('metric_samples', [
                        'project_id' => $projectId,
                        'key' => $key,
                        'captured_at' => $capturedAt->toDateTimeString(),
                    ], [
                        'value' => $value,
                        'meta' => json_encode(['source' => 'demo-seeder']),
                        'created_at' => $capturedAt,
                        'updated_at' => $capturedAt,
                    ]);
                }
            }
        }
    }

    private function seedThresholds(string $projectId, $now): void
    {
        $thresholds = [
            ['key' => 'connection_pool_used', 'operator' => 'gt', 'value' => 80, 'enabled' => true],
            ['key' => 'slow_queries_per_min', 'operator' => 'gt', 'value' => 5, 'enabled' => true],
            ['key' => 'connection_pool_used', 'operator' => 'gt', 'value' => 90, 'enabled' => false],
        ];

        foreach ($thresholds as $threshold) {
            $this->ensureRow('thresholds', [
                'project_id' => $projectId,
                'key' => $threshold['key'],
                'operator' => $threshold['operator'],
                'value' => $threshold['value'],
            ], [
                'enabled' => $threshold['enabled'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensureRow(string $table, array $key, array $data): string
    {
        $existing = DB::table($table)->where($key)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table($table)->insert(array_merge(['id' => $id], $key, $data));

        return $id;
    }

    private function ensureEmpty(string $table, array $where, Closure $generator): void
    {
        if (DB::table($table)->where($where)->exists()) {
            return;
        }

        $generator();
    }
}
