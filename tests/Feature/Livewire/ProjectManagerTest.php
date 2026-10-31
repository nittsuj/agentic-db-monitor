<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActionDecision;
use App\Enums\DbDriver;
use App\Enums\PrivilegeLevel;
use App\Livewire\Projects\ProjectManager;
use App\Models\ActionLog;
use App\Models\ChatSession;
use App\Models\MetricSample;
use App\Models\Project;
use App\Models\Threshold;
use App\Services\Database\PermissionChecker;
use App\Services\Database\PermissionCheckResult;
use App\Services\Database\Ssh\SshTunnelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ProjectManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_edits_ssl_settings_and_keeps_other_options(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'options' => ['source' => 'demo-seeder', 'ssl' => ['mode' => 'require', 'ca' => null]],
        ]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->assertSet('sslMode', 'require')
            ->assertSet('sslCa', '')
            ->set('sslMode', 'disable')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('disable', $project->options['ssl']['mode']);
        $this->assertSame('demo-seeder', $project->options['source']);
    }

    public function test_test_connection_uses_the_stored_password_and_shows_the_result(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'username' => 'postgres',
            'password' => 'stored-secret',
        ]);

        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->withArgs(fn (...$args) => ($args['password'] ?? $args[5] ?? null) === 'stored-secret')
            ->andReturn(PermissionCheckResult::success(
                privilegeLevel: PrivilegeLevel::Admin,
                privilegeNotes: ["'postgres' is a superuser."],
                currentUser: 'postgres',
            ));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->call('testConnection')
            ->assertSet('privilegeLevel', 'admin')
            ->assertSee('Terhubung sebagai superuser / admin');

        // Testing never saves.
        $this->assertSame('stored-secret', $project->fresh()->password);
    }

    public function test_test_connection_reports_a_clean_pass(): void
    {
        $project = Project::factory()->create();

        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')->once()
            ->andReturn(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::ReadOnly));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->call('testConnection')
            ->assertSet('notice', 'Koneksi berhasil, semua pemeriksaan lulus.');
    }

    public function test_editing_keeps_the_stored_ssh_key_when_left_empty(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----";

        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'options' => ['ssh' => [
                'enabled' => true,
                'host' => 'old-bastion',
                'port' => 22,
                'username' => 'tunnel',
                'auth' => 'key',
                'private_key' => SshTunnelConfig::encrypt($key),
                'passphrase' => null,
            ]],
        ]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->assertSet('sshEnabled', true)
            ->assertSet('sshHasStoredKey', true)
            ->assertSet('sshPrivateKey', '')
            ->set('sshHost', 'new-bastion')
            ->call('save')
            ->assertHasNoErrors();

        $ssh = $project->fresh()->options['ssh'];

        $this->assertSame('new-bastion', $ssh['host']);
        $this->assertSame($key, SshTunnelConfig::decrypt($ssh['private_key']));
    }

    public function test_switching_ssh_auth_method_and_back_keeps_both_secrets(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----";

        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'options' => ['ssh' => [
                'enabled' => true,
                'host' => 'bastion',
                'port' => 22,
                'username' => 'tunnel',
                'auth' => 'key',
                'private_key' => SshTunnelConfig::encrypt($key),
                'passphrase' => SshTunnelConfig::encrypt('phrase'),
            ]],
        ]);

        $component = Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('sshAuth', 'password')
            ->set('sshPassword', 'pw')
            ->call('save')
            ->assertHasNoErrors();

        $ssh = $project->fresh()->options['ssh'];
        $this->assertSame('password', $ssh['auth']);
        $this->assertSame('pw', SshTunnelConfig::decrypt($ssh['password']));
        $this->assertSame($key, SshTunnelConfig::decrypt($ssh['private_key']));
        $this->assertSame('phrase', SshTunnelConfig::decrypt($ssh['passphrase']));

        // Back to key auth without pasting the key again.
        $component
            ->assertSet('sshHasStoredKey', true)
            ->set('sshAuth', 'key')
            ->call('save')
            ->assertHasNoErrors();

        $ssh = $project->fresh()->options['ssh'];
        $this->assertSame('key', $ssh['auth']);
        $this->assertSame($key, SshTunnelConfig::decrypt($ssh['private_key']));
        $this->assertSame('phrase', SshTunnelConfig::decrypt($ssh['passphrase']));
        $this->assertSame('pw', SshTunnelConfig::decrypt($ssh['password']));

        // And to password auth again without retyping the password.
        $component
            ->assertSet('sshHasStoredPassword', true)
            ->set('sshAuth', 'password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('pw', SshTunnelConfig::decrypt($project->fresh()->options['ssh']['password']));
    }

    public function test_disabling_ssh_keeps_the_stored_settings(): void
    {
        $project = Project::factory()->create([
            'options' => ['ssh' => ['enabled' => true, 'host' => 'bastion', 'port' => 22, 'username' => 'tunnel', 'auth' => 'password', 'password' => SshTunnelConfig::encrypt('pw')]],
        ]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('sshEnabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $ssh = $project->fresh()->options['ssh'];

        $this->assertFalse($ssh['enabled']);
        $this->assertSame('pw', SshTunnelConfig::decrypt($ssh['password']));
    }

    public function test_projects_without_ssl_options_edit_as_prefer(): void
    {
        $project = Project::factory()->create(['options' => []]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->assertSet('sslMode', 'prefer');
    }

    public function test_lists_the_projects(): void
    {
        Project::factory()->create(['name' => 'Produksi MySQL']);

        Livewire::test(ProjectManager::class)
            ->assertSee('Kelola project')
            ->assertSee('Produksi MySQL');
    }

    public function test_edits_the_connection_details_of_a_project(): void
    {
        $project = Project::factory()->create([
            'name' => 'Lama',
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
        ]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->assertSet('name', 'Lama')
            ->set('name', 'Produksi')
            ->set('host', 'db.internal')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Project "Produksi" diperbarui.');

        $project->refresh();

        $this->assertSame('Produksi', $project->name);
        $this->assertSame('db.internal', $project->host);
    }

    public function test_keeps_the_stored_password_when_the_field_is_left_empty(): void
    {
        $project = Project::factory()->create(['password' => 'original-secret']);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('name', 'Tetap sama')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('original-secret', $project->refresh()->password);
    }

    public function test_replaces_the_password_when_a_new_one_is_typed(): void
    {
        $project = Project::factory()->create(['password' => 'original-secret']);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('password', 'new-secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('new-secret', $project->refresh()->password);
    }

    public function test_rejects_a_duplicate_connection_tuple(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        Livewire::test(ProjectManager::class)
            ->call('edit', $other->id)
            ->set('host', $project->host)
            ->set('port', $project->port)
            ->set('database', $project->database)
            ->set('username', $project->username)
            ->set('driver', $project->driver->value)
            ->call('save')
            ->assertSet('errorMessage', 'A database profile with these exact connection details already exists.');

        $this->assertNotSame($project->database, $other->refresh()->database);
        $this->assertNotSame($project->username, $other->username);
    }

    public function test_deletes_a_project_and_keeps_the_audit_log(): void
    {
        $project = Project::factory()->create();

        ChatSession::factory()->for($project)->create();
        MetricSample::factory()->for($project)->create();
        Threshold::factory()->for($project)->create();

        ActionLog::factory()->for($project)->create([
            'decision' => ActionDecision::Executed,
            'project_name' => $project->name,
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->call('delete');

        $this->assertNull(Project::find($project->id));
        $this->assertSame(0, MetricSample::where('project_id', $project->id)->count());
        $this->assertSame(0, Threshold::where('project_id', $project->id)->count());
        $this->assertNull(session('active_project_id'));

        $log = ActionLog::firstOrFail();
        $this->assertNull($log->project_id);
        $this->assertSame($project->name, $log->project_name);
    }
}
