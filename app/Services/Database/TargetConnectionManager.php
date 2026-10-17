<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DbDriver;
use App\Models\Project;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;

class TargetConnectionManager
{
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
            host: $project->host,
            port: $project->port,
            database: $project->database,
            username: $project->username,
            password: $project->password,
            options: $project->options ?? [],
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
            host: $host,
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
                'options' => $pdoOptions + ($options['pdo'] ?? []),
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
                'sslmode' => 'prefer',
                'options' => $pdoOptions + ($options['pdo'] ?? []),
            ],
        };
    }
}
