<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbDriver;
use App\Models\AiSetting;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_app_tables_exist(): void
    {
        foreach (['projects', 'ai_settings', 'chat_sessions', 'chat_messages', 'pending_actions', 'action_logs', 'metric_samples', 'thresholds'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table: {$table}");
        }
    }

    public function test_projects_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', [
            'id', 'name', 'driver', 'host', 'port', 'database', 'username', 'password',
            'options', 'is_active', 'created_at', 'updated_at',
        ]));
    }

    public function test_ai_settings_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_settings', [
            'id', 'provider', 'model', 'base_url', 'api_key', 'is_active', 'created_at', 'updated_at',
        ]));
    }

    public function test_chat_sessions_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('chat_sessions', [
            'id', 'project_id', 'title', 'created_at', 'updated_at',
        ]));
    }

    public function test_chat_messages_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('chat_messages', [
            'id', 'chat_session_id', 'role', 'content', 'meta', 'created_at', 'updated_at',
        ]));
    }

    public function test_pending_actions_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('pending_actions', [
            'id', 'project_id', 'chat_session_id', 'engine', 'category', 'command', 'status',
            'meta', 'proposed_at', 'decided_at', 'executed_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_action_logs_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('action_logs', [
            'id', 'project_id', 'project_name', 'engine', 'pending_action_id', 'chat_session_id',
            'decision', 'command', 'actor', 'result', 'error', 'executed_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_metric_samples_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('metric_samples', [
            'id', 'project_id', 'key', 'value', 'captured_at', 'meta', 'created_at', 'updated_at',
        ]));
    }

    public function test_thresholds_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('thresholds', [
            'id', 'project_id', 'key', 'operator', 'value', 'enabled', 'created_at', 'updated_at',
        ]));
    }

    public function test_projects_unique_constraint(): void
    {
        $tuple = [
            'name' => 'A',
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'playground',
            'username' => 'root',
            'password' => 'secret',
        ];
        Project::factory()->create($tuple);

        $this->expectException(QueryException::class);
        Project::factory()->create($tuple);
    }

    public function test_uuid_v7_primary_keys(): void
    {
        $project = Project::factory()->create();
        $this->assertTrue(Str::isUuid($project->id));
        $this->assertSame('7', $project->id[14]);
    }

    public function test_enum_cast_persists_lowercase_and_rejects_invalid(): void
    {
        $project = Project::factory()->create(['driver' => DbDriver::MySql]);
        $this->assertSame(DbDriver::MySql, $project->fresh()->driver);
        $this->assertSame('mysql', $project->fresh()->getRawOriginal('driver'));

        $this->expectException(\ValueError::class);
        $project->driver = 'bogus';
        $project->save();
    }

    public function test_encrypted_cast_round_trip(): void
    {
        $project = Project::factory()->create(['password' => 'secret']);
        $raw = \DB::table('projects')->where('id', $project->id)->value('password');
        $this->assertNotSame('secret', $raw);
        $this->assertSame('secret', $project->fresh()->password);

        $setting = AiSetting::factory()->create(['api_key' => 'ollama-demo-key']);
        $rawKey = \DB::table('ai_settings')->where('id', $setting->id)->value('api_key');
        $this->assertNotSame('ollama-demo-key', $rawKey);
        $this->assertSame('ollama-demo-key', $setting->fresh()->api_key);
    }

    public function test_composite_indexes_exist(): void
    {
        $indexes = collect(\DB::select("SELECT sql FROM sqlite_master WHERE type='index'"))
            ->pluck('sql')
            ->filter()
            ->implode("\n");

        $this->assertStringContainsString('chat_session_id', $indexes);
        $this->assertStringContainsString('captured_at', $indexes);
    }
}
