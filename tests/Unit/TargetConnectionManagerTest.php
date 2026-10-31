<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DbDriver;
use App\Models\Project;
use App\Services\Database\CaCertificateStore;
use App\Services\Database\Ssh\SshTunnel;
use App\Services\Database\Ssh\SshTunnelConfig;
use App\Services\Database\Ssh\SshTunnelManager;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\Support\TestCertificate;
use Tests\TestCase;

class TargetConnectionManagerTest extends TestCase
{
    use RefreshDatabase;

    protected TargetConnectionManager $manager;

    protected string $caDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->caDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adbm-ca-'.uniqid();
        $this->manager = new TargetConnectionManager($this->store('/etc/ssl/system-bundle.pem'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->caDirectory);
        parent::tearDown();
    }

    protected function store(?string $systemBundle): CaCertificateStore
    {
        return new class($this->caDirectory, $systemBundle) extends CaCertificateStore
        {
            public function __construct(?string $directory, private ?string $bundle)
            {
                parent::__construct($directory);
            }

            public function systemBundle(): ?string
            {
                return $this->bundle;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $ssl
     * @return array<string, mixed>
     */
    protected function configFor(DbDriver $driver, array $ssl): array
    {
        $project = Project::factory()->create([
            'driver' => $driver,
            'options' => $ssl === [] ? [] : ['ssl' => $ssl],
        ]);

        $this->manager->connect($project);
        $name = $this->manager->connectionName($project);
        $config = Config::get("database.connections.{$name}");
        $this->manager->purge($name);

        return $config;
    }

    protected function mySqlAttribute(string $name): int
    {
        $modern = 'Pdo\\Mysql::ATTR_'.$name;

        return defined($modern) ? constant($modern) : constant("PDO::MYSQL_ATTR_{$name}");
    }

    public function test_ssh_enabled_project_connects_through_the_local_end_of_the_tunnel(): void
    {
        $tunnels = new class extends SshTunnelManager
        {
            /** @var list<array{0: SshTunnelConfig, 1: string, 2: int}> */
            public array $opened = [];

            public function open(SshTunnelConfig $config, string $remoteHost, int $remotePort, int $timeout = 10): SshTunnel
            {
                $this->opened[] = [$config, $remoteHost, $remotePort];

                return new SshTunnel(45678, null);
            }
        };

        $this->manager = new TargetConnectionManager($this->store(null), $tunnels);

        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            // localhost here is the bastion itself, so it must not be rewritten.
            'host' => 'localhost',
            'port' => 5432,
            'options' => ['ssh' => [
                'enabled' => true,
                'host' => 'bastion.example.com',
                'port' => 22,
                'username' => 'tunnel',
                'auth' => 'key',
                'private_key' => SshTunnelConfig::encrypt('KEY'),
            ]],
        ]);

        $this->manager->connect($project);
        $name = $this->manager->connectionName($project);

        $this->assertSame('127.0.0.1', Config::get("database.connections.{$name}.host"));
        $this->assertSame(45678, Config::get("database.connections.{$name}.port"));

        [$config, $remoteHost, $remotePort] = $tunnels->opened[0];
        $this->assertSame('bastion.example.com', $config->host);
        $this->assertSame('KEY', $config->privateKey);
        $this->assertSame('localhost', $remoteHost);
        $this->assertSame(5432, $remotePort);

        $this->manager->purge($name);
    }

    /**
     * A tunnel manager whose tunnels own a marker temp file. SshTunnel::close()
     * deletes its temp files, so the marker disappearing proves the tunnel was
     * closed. The fake keeps no reference to the tunnel, so only the manager's
     * own bookkeeping can keep it (and the marker) alive.
     */
    protected function markerTunnels(string $marker): SshTunnelManager
    {
        return new class($marker) extends SshTunnelManager
        {
            public function __construct(private string $marker)
            {
                parent::__construct();
            }

            public function open(SshTunnelConfig $config, string $remoteHost, int $remotePort, int $timeout = 10): SshTunnel
            {
                touch($this->marker);

                return new SshTunnel(45678, null, [$this->marker]);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function sshOptionsWithUnsatisfiableCa(): array
    {
        return $this->sshOptions(['mode' => 'verify-ca']);
    }

    /**
     * @param  array<string, mixed>  $ssl
     * @return array<string, mixed>
     */
    protected function sshOptions(array $ssl): array
    {
        return [
            'ssl' => $ssl,
            'ssh' => [
                'enabled' => true,
                'host' => 'bastion.example.com',
                'port' => 22,
                'username' => 'tunnel',
                'auth' => 'key',
                'private_key' => SshTunnelConfig::encrypt('KEY'),
            ],
        ];
    }

    public function test_temporary_connection_closes_the_tunnel_when_building_the_config_fails(): void
    {
        $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adbm-tunnel-'.uniqid();
        $this->manager = new TargetConnectionManager($this->store(null), $this->markerTunnels($marker));

        try {
            $this->manager->connectTemporary(
                driver: DbDriver::PgSql,
                host: 'db.internal',
                port: 5432,
                database: 'app',
                username: 'app',
                password: 'secret',
                options: $this->sshOptionsWithUnsatisfiableCa(),
            );
            $this->fail('Expected the missing CA to abort the connection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No CA certificate available', $e->getMessage());
        }

        $this->assertFileDoesNotExist($marker);
    }

    public function test_connect_closes_the_tunnel_when_building_the_config_fails(): void
    {
        $marker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adbm-tunnel-'.uniqid();
        $this->manager = new TargetConnectionManager($this->store(null), $this->markerTunnels($marker));

        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'options' => $this->sshOptionsWithUnsatisfiableCa(),
        ]);

        try {
            $this->manager->connect($project);
            $this->fail('Expected the missing CA to abort the connection.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No CA certificate available', $e->getMessage());
        }

        $this->assertFileDoesNotExist($marker);
    }

    /**
     * A tunnel manager that records the timeout of every open().
     */
    protected function timeoutRecordingTunnels(): SshTunnelManager
    {
        return new class extends SshTunnelManager
        {
            /** @var list<int> */
            public array $timeouts = [];

            public function open(SshTunnelConfig $config, string $remoteHost, int $remotePort, int $timeout = 10): SshTunnel
            {
                $this->timeouts[] = $timeout;

                return new SshTunnel(45678, null);
            }
        };
    }

    public function test_connect_opens_the_tunnel_with_the_fail_fast_timeout(): void
    {
        $tunnels = $this->timeoutRecordingTunnels();
        $this->manager = new TargetConnectionManager($this->store(null), $tunnels);

        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'options' => $this->sshOptions(['mode' => 'prefer']),
        ]);

        $this->manager->connect($project);
        $name = $this->manager->connectionName($project);

        $this->assertSame([5], $tunnels->timeouts);
        $this->assertSame(5, Config::get("database.connections.{$name}.options")[PDO::ATTR_TIMEOUT]);

        $this->manager->purge($name);
    }

    public function test_temporary_connection_opens_the_tunnel_with_the_fail_fast_timeout(): void
    {
        $tunnels = $this->timeoutRecordingTunnels();
        $this->manager = new TargetConnectionManager($this->store(null), $tunnels);

        $connection = $this->manager->connectTemporary(
            driver: DbDriver::MySql,
            host: 'db.internal',
            port: 3306,
            database: 'app',
            username: 'app',
            password: 'secret',
            options: $this->sshOptions(['mode' => 'disable']),
        );

        $this->assertSame([5], $tunnels->timeouts);

        $this->manager->purge($connection->getName());
    }

    public function test_pgsql_defaults_to_prefer_without_ssl_options(): void
    {
        $config = $this->configFor(DbDriver::PgSql, []);

        $this->assertSame('prefer', $config['sslmode']);
        $this->assertArrayNotHasKey('sslrootcert', $config);
    }

    public function test_pgsql_passes_the_selected_mode(): void
    {
        foreach (['disable', 'prefer', 'require'] as $mode) {
            $config = $this->configFor(DbDriver::PgSql, ['mode' => $mode]);

            $this->assertSame($mode, $config['sslmode']);
            $this->assertArrayNotHasKey('sslrootcert', $config);
        }
    }

    public function test_pgsql_verify_modes_use_the_pasted_ca(): void
    {
        $config = $this->configFor(DbDriver::PgSql, ['mode' => 'verify-full', 'ca' => TestCertificate::pem()]);

        $this->assertSame('verify-full', $config['sslmode']);
        $this->assertFileExists($config['sslrootcert']);
        $this->assertStringStartsWith($this->caDirectory, $config['sslrootcert']);
    }

    public function test_pgsql_verify_modes_fall_back_to_the_system_bundle(): void
    {
        $config = $this->configFor(DbDriver::PgSql, ['mode' => 'verify-ca']);

        $this->assertSame('/etc/ssl/system-bundle.pem', $config['sslrootcert']);
    }

    public function test_verify_mode_without_any_ca_fails(): void
    {
        $this->manager = new TargetConnectionManager($this->store(null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No CA certificate available');

        $this->configFor(DbDriver::PgSql, ['mode' => 'verify-full']);
    }

    public function test_mysql_sends_no_ssl_attributes_for_disable_and_prefer(): void
    {
        foreach (['disable', 'prefer'] as $mode) {
            $options = $this->configFor(DbDriver::MySql, ['mode' => $mode])['options'];

            $this->assertArrayNotHasKey($this->mySqlAttribute('SSL_CA'), $options);
            $this->assertArrayNotHasKey($this->mySqlAttribute('SSL_VERIFY_SERVER_CERT'), $options);
        }
    }

    public function test_mysql_require_encrypts_without_verifying(): void
    {
        $options = $this->configFor(DbDriver::MySql, ['mode' => 'require'])['options'];

        $this->assertSame('/etc/ssl/system-bundle.pem', $options[$this->mySqlAttribute('SSL_CA')]);
        $this->assertFalse($options[$this->mySqlAttribute('SSL_VERIFY_SERVER_CERT')]);
    }

    public function test_mysql_verify_uses_the_pasted_ca_and_verifies(): void
    {
        $options = $this->configFor(DbDriver::MySql, ['mode' => 'verify-ca', 'ca' => TestCertificate::pem()])['options'];

        $this->assertFileExists($options[$this->mySqlAttribute('SSL_CA')]);
        $this->assertTrue($options[$this->mySqlAttribute('SSL_VERIFY_SERVER_CERT')]);
        // The built-in timeout and error mode still apply.
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function test_connection_name_generation(): void
    {
        $project = Project::factory()->create();

        $this->assertSame("target_{$project->id}", $this->manager->connectionName($project));
        $this->assertSame('target_sample-uuid', $this->manager->connectionName('sample-uuid'));
    }

    public function test_connect_registers_dynamic_mysql_connection(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'secret123',
        ]);

        $connection = $this->manager->connect($project);
        $connName = $this->manager->connectionName($project);

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertSame('mysql', Config::get("database.connections.{$connName}.driver"));
        $this->assertSame('test_db', Config::get("database.connections.{$connName}.database"));
        $this->assertSame('test_user', Config::get("database.connections.{$connName}.username"));
        $this->assertSame('secret123', Config::get("database.connections.{$connName}.password"));

        $this->manager->purge($connName);
        $this->assertNull(Config::get("database.connections.{$connName}"));
    }

    public function test_connect_registers_dynamic_pgsql_connection(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'pg_db',
            'username' => 'pg_user',
            'password' => 'pg_secret',
        ]);

        $connection = $this->manager->connect($project);
        $connName = $this->manager->connectionName($project);

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertSame('pgsql', Config::get("database.connections.{$connName}.driver"));
        $this->assertSame('pg_db', Config::get("database.connections.{$connName}.database"));
        $this->assertSame('pg_user', Config::get("database.connections.{$connName}.username"));
        $this->assertSame('pg_secret', Config::get("database.connections.{$connName}.password"));
        // libpq must give up quickly on an unreachable host instead of hanging.
        $this->assertSame('5', getenv('PGCONNECT_TIMEOUT'));

        $this->manager->purge($connName);
    }
}
