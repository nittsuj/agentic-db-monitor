<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\DbDriver;
use App\Enums\PrivilegeLevel;
use App\Livewire\Projects\CreateProjectModal;
use App\Models\Project;
use App\Services\Database\PermissionChecker;
use App\Services\Database\PermissionCheckResult;
use App\Services\Database\Ssh\SshTunnelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Support\TestCertificate;
use Tests\TestCase;

class CreateProjectModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully(): void
    {
        Livewire::test(CreateProjectModal::class)
            ->assertStatus(200)
            ->assertSee('Add Database Profile');
    }

    public function test_driver_update_sets_default_port_and_username(): void
    {
        Livewire::test(CreateProjectModal::class)
            ->assertSet('port', 3306)
            ->assertSet('username', 'root')
            ->set('driver', 'pgsql')
            ->assertSet('port', 5432)
            ->assertSet('username', 'postgres')
            ->set('driver', 'mysql')
            ->assertSet('port', 3306)
            ->assertSet('username', 'root');
    }

    public function test_displays_error_when_permission_check_fails(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->andReturn(PermissionCheckResult::failure('Authentication failed for user root'));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'My DB')
            ->set('driver', 'mysql')
            ->set('host', '127.0.0.1')
            ->set('port', 3306)
            ->set('database', 'mydb')
            ->set('username', 'root')
            ->set('password', 'wrong_pass')
            ->call('save')
            ->assertSet('errorMessage', 'Authentication failed for user root')
            ->assertSee('Authentication failed for user root');

        $this->assertDatabaseEmpty('projects');
    }

    public function test_saves_project_and_sets_active_session_when_check_succeeds(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->andReturn(PermissionCheckResult::success(['Read access verified']));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Production Warehouse')
            ->set('driver', 'mysql')
            ->set('host', '10.0.0.5')
            ->set('port', 3306)
            ->set('database', 'warehouse')
            ->set('username', 'svc_monitor')
            ->set('password', 'strongpass')
            ->call('save')
            ->assertDispatched('project-created')
            ->assertRedirect(route('dashboard'));

        $project = Project::where('name', 'Production Warehouse')->first();
        $this->assertNotNull($project);
        $this->assertSame(DbDriver::MySql, $project->driver);
        $this->assertSame('strongpass', $project->password);
        $this->assertSame($project->id, session('active_project_id'));
    }

    public function test_rejects_duplicate_project_tuples(): void
    {
        Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => '10.0.0.5',
            'port' => 3306,
            'database' => 'warehouse',
            'username' => 'svc_monitor',
        ]);

        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->andReturn(PermissionCheckResult::success());

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Duplicate DB')
            ->set('driver', 'mysql')
            ->set('host', '10.0.0.5')
            ->set('port', 3306)
            ->set('database', 'warehouse')
            ->set('username', 'svc_monitor')
            ->call('save')
            ->assertSee('A database profile with these exact connection details already exists.');
    }

    public function test_passes_ssl_options_to_the_checker_and_stores_them(): void
    {
        $pem = TestCertificate::pem();

        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->withArgs(fn (...$args) => ($args['options'] ?? $args[6] ?? null) === ['ssl' => ['mode' => 'verify-full', 'ca' => trim($pem)]])
            ->andReturn(PermissionCheckResult::success(['Read access verified']));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Managed PG')
            ->set('driver', 'pgsql')
            ->set('host', 'db.example.com')
            ->set('database', 'app')
            ->set('username', 'adbm_ro')
            ->set('sslMode', 'verify-full')
            ->set('sslCa', $pem)
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();
        $this->assertSame('verify-full', $project->options['ssl']['mode']);
        $this->assertSame(trim($pem), $project->options['ssl']['ca']);
    }

    public function test_rejects_an_invalid_ca_certificate(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldNotReceive('check');
        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Managed PG')
            ->set('database', 'app')
            ->set('sslMode', 'verify-ca')
            ->set('sslCa', 'not a certificate')
            ->call('save')
            ->assertHasErrors(['sslCa']);

        $this->assertDatabaseEmpty('projects');
    }

    public function test_admin_connection_is_shown_before_saving_and_needs_a_second_click(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->andReturn(PermissionCheckResult::success(
                passedChecks: ['Read access to pg_catalog'],
                privilegeLevel: PrivilegeLevel::Admin,
                privilegeNotes: ["'postgres' is a superuser."],
                currentUser: 'postgres',
            ));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        $component = Livewire::test(CreateProjectModal::class)
            ->set('name', 'Prod')
            ->set('driver', 'pgsql')
            ->set('host', 'db.example.com')
            ->set('database', 'shop')
            ->call('save')
            ->assertSet('reviewing', true)
            ->assertSet('privilegeLevel', 'admin')
            ->assertSee('Terhubung sebagai superuser / admin')
            ->assertSee('CREATE ROLE adbm_ro LOGIN')
            ->assertSee('Tetap simpan dengan user admin');

        $this->assertDatabaseEmpty('projects');

        // The reviewed result is reused; the checker is not called again.
        $component->call('save')->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('projects', 1);
    }

    public function test_missing_features_are_shown_before_saving(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->andReturn(PermissionCheckResult::success(
                warnings: ["Extension 'pg_stat_statements' is not installed in this database."],
                privilegeLevel: PrivilegeLevel::ReadOnly,
            ));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Prod')
            ->set('database', 'shop')
            ->call('save')
            ->assertSet('reviewing', true)
            ->assertSee('Fitur pemantauan yang belum tersedia')
            ->assertSee('pg_stat_statements');

        $this->assertDatabaseEmpty('projects');
    }

    public function test_changing_a_connection_field_after_review_tests_again(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->twice()
            ->andReturn(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::Admin));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Prod')
            ->set('database', 'shop')
            ->call('save')
            ->assertSet('reviewing', true)
            ->set('username', 'adbm_ro')
            ->assertSet('reviewing', false)
            ->call('save')
            ->assertSet('reviewing', true);

        $this->assertDatabaseEmpty('projects');
    }

    public function test_stores_ssh_settings_with_encrypted_secrets(): void
    {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----";

        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldReceive('check')
            ->once()
            ->withArgs(fn (...$args) => SshTunnelConfig::fromOptions($args['options'] ?? $args[6])?->privateKey === $key)
            ->andReturn(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::ReadOnly));

        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Behind bastion')
            ->set('driver', 'pgsql')
            ->set('host', '10.0.1.20')
            ->set('database', 'shop')
            ->set('sshEnabled', true)
            ->set('sshHost', 'bastion.example.com')
            ->set('sshUsername', 'tunnel')
            ->set('sshPrivateKey', $key)
            ->set('sshPassphrase', 'phrase')
            ->call('save')
            ->assertHasNoErrors();

        $ssh = Project::sole()->options['ssh'];

        $this->assertTrue($ssh['enabled']);
        $this->assertSame('bastion.example.com', $ssh['host']);
        $this->assertStringNotContainsString('OPENSSH', $ssh['private_key']);
        $this->assertNotSame('phrase', $ssh['passphrase']);
        $this->assertSame($key, SshTunnelConfig::decrypt($ssh['private_key']));
    }

    public function test_ssh_requires_a_key_and_rejects_hostname_verification(): void
    {
        $mockChecker = Mockery::mock(PermissionChecker::class);
        $mockChecker->shouldNotReceive('check');
        $this->app->instance(PermissionChecker::class, $mockChecker);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Behind bastion')
            ->set('driver', 'pgsql')
            ->set('database', 'shop')
            ->set('sslMode', 'verify-full')
            ->set('sshEnabled', true)
            ->set('sshHost', 'bastion.example.com')
            ->set('sshUsername', 'tunnel')
            ->call('save')
            ->assertHasErrors(['sshPrivateKey', 'sslMode']);

        Livewire::test(CreateProjectModal::class)
            ->set('name', 'Behind bastion')
            ->set('driver', 'mysql')
            ->set('database', 'shop')
            ->set('sslMode', 'verify-ca')
            ->set('sshEnabled', true)
            ->set('sshHost', 'bastion.example.com')
            ->set('sshUsername', 'tunnel')
            ->set('sshPrivateKey', 'not a key')
            ->call('save')
            ->assertHasErrors(['sshPrivateKey', 'sslMode']);
    }

    public function test_managed_provider_hosts_default_to_require(): void
    {
        Livewire::test(CreateProjectModal::class)
            ->assertSet('sslMode', 'prefer')
            ->set('host', 'db.abcdefgh.supabase.co')
            ->assertSet('sslMode', 'require');

        Livewire::test(CreateProjectModal::class)
            ->set('sslMode', 'disable')
            ->set('host', 'mydb.abc123.ap-southeast-1.rds.amazonaws.com')
            ->assertSet('sslMode', 'disable');
    }
}
