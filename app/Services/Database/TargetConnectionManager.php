<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DbDriver;
use App\Enums\SslMode;
use App\Models\Project;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class TargetConnectionManager
{
    protected CaCertificateStore $certificates;

    public function __construct(?CaCertificateStore $certificates = null)
    {
        $this->certificates = $certificates ?? new CaCertificateStore;
    }

    /**
     * Build connection name for a project.
     */
    public function connectionName(Project|string $project): string
    {
        $id = $project instanceof Project ? $project->id : $project;

        return "target_{$id}";
    }

    /**
     * Register and return a dynamic runtime connection for a project.
     */
    public function connect(Project $project): ConnectionInterface
    {
        $name = $this->connectionName($project);

        $config = $this->buildConfig(
            driver: $project->driver,
            host: $this->resolveHost($project->host),
            port: $project->port,
            database: $project->database,
            username: $project->username,
            password: $project->password,
            options: $project->options ?? [],
            // Chat and the collector run inside a web/scheduler request, so an
            // unreachable target must fail fast rather than hold the worker.
            timeout: 5,
        );

        Config::set("database.connections.{$name}", $config);

        return DB::connection($name);
    }

    /**
     * Create and return a temporary connection for credential verification.
     *
     * @param  array<string, mixed>  $options
     */
    public function connectTemporary(
        DbDriver $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        array $options = [],
    ): ConnectionInterface {
        $tempName = 'target_temp_verify_'.uniqid();

        $config = $this->buildConfig(
            driver: $driver,
            host: $this->resolveHost($host),
            port: $port,
            database: $database,
            username: $username,
            password: $password,
            options: $options,
            timeout: 5,
        );

        Config::set("database.connections.{$tempName}", $config);

        return DB::connection($tempName);
    }

    /**
     * Disconnect and purge connection from pool and configuration.
     */
    public function purge(string $connectionName): void
    {
        DB::purge($connectionName);
        Config::set("database.connections.{$connectionName}", null);
    }

    /**
     * Resolve loopback target hosts when the app runs inside Docker.
     */
    protected function resolveHost(string $host): string
    {
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $host;
        }

        return (string) env('TARGET_DB_HOST', $host);
    }

    /**
     * Build database connection configuration array for Laravel.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildConfig(
        DbDriver $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        array $options = [],
        int $timeout = 10,
    ): array {
        // pdo_pgsql ignores PDO::ATTR_TIMEOUT and Laravel's Postgres DSN has no
        // connect_timeout key, so an unreachable host would hold the request (and a
        // server worker) for the OS TCP timeout. libpq reads this variable instead.
        if ($driver === DbDriver::PgSql) {
            putenv('PGCONNECT_TIMEOUT='.max(1, $timeout));
        }

        $pdoOptions = [
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];

        return match ($driver) {
            DbDriver::MySql => [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => $pdoOptions + $this->mySqlSslOptions($options) + ($options['pdo'] ?? []),
            ],
            DbDriver::PgSql => [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'options' => $pdoOptions + ($options['pdo'] ?? []),
            ] + $this->pgSqlSslConfig($options),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function sslMode(array $options): SslMode
    {
        return SslMode::tryFrom((string) ($options['ssl']['mode'] ?? '')) ?? SslMode::Prefer;
    }

    /**
     * Laravel's Postgres connector copies these keys into the libpq DSN.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function pgSqlSslConfig(array $options): array
    {
        $mode = $this->sslMode($options);
        $config = ['sslmode' => $mode->value];

        if ($mode->verifiesServer()) {
            $config['sslrootcert'] = $this->caPath($options);
        } elseif ($mode === SslMode::Require && ($pem = $this->pastedCa($options)) !== null) {
            // libpq verifies against sslrootcert when it is set, even in require mode.
            $config['sslrootcert'] = $this->certificates->path($pem);
        }

        return $config;
    }

    /**
     * mysqlnd only negotiates TLS when an SSL attribute is set, so disable and
     * prefer send none (plain connection, as before).
     *
     * @param  array<string, mixed>  $options
     * @return array<int, mixed>
     */
    protected function mySqlSslOptions(array $options): array
    {
        $mode = $this->sslMode($options);

        if (! $mode->encrypts()) {
            return [];
        }

        return [
            $this->mySqlAttribute('SSL_CA') => $this->caPath($options),
            $this->mySqlAttribute('SSL_VERIFY_SERVER_CERT') => $mode->verifiesServer(),
        ];
    }

    /**
     * PHP 8.4 moved the driver constants to Pdo\Mysql and 8.5 deprecates the
     * PDO::MYSQL_ATTR_* aliases.
     */
    protected function mySqlAttribute(string $name): int
    {
        $modern = 'Pdo\\Mysql::ATTR_'.$name;

        return defined($modern) ? constant($modern) : constant("PDO::MYSQL_ATTR_{$name}");
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function caPath(array $options): string
    {
        $pem = $this->pastedCa($options);

        if ($pem !== null) {
            return $this->certificates->path($pem);
        }

        return $this->certificates->systemBundle()
            ?? throw new RuntimeException('No CA certificate available for SSL verification. Paste the provider CA certificate.');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function pastedCa(array $options): ?string
    {
        $pem = trim((string) ($options['ssl']['ca'] ?? ''));

        return $pem === '' ? null : $pem;
    }
}
