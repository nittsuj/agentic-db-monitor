<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DbDriver;
use Illuminate\Database\ConnectionInterface;
use Throwable;

class PermissionChecker
{
    public function __construct(
        protected TargetConnectionManager $connectionManager,
    ) {}

    /**
     * Check if database credentials and privileges meet monitoring requirements.
     *
     * @param  array<string, mixed>  $options
     */
    public function check(
        DbDriver $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        array $options = [],
    ): PermissionCheckResult {
        $connection = null;
        $tempConnectionName = null;

        try {
            $connection = $this->connectionManager->connectTemporary(
                driver: $driver,
                host: $host,
                port: $port,
                database: $database,
                username: $username,
                password: $password,
                options: $options,
            );

            $tempConnectionName = $connection->getName();

            // Test base connectivity
            $connection->getPdo();

            return match ($driver) {
                DbDriver::MySql => $this->checkMySql($connection),
                DbDriver::PgSql => $this->checkPgSql($connection),
            };
        } catch (Throwable $e) {
            return PermissionCheckResult::failure(
                errorMessage: 'Connection failed: '.$e->getMessage(),
            );
        } finally {
            if ($tempConnectionName !== null) {
                $this->connectionManager->purge($tempConnectionName);
            }
        }
    }

    /**
     * Check MySQL specific permissions: information_schema, performance_schema, processlist.
     */
    protected function checkMySql(ConnectionInterface $connection): PermissionCheckResult
    {
        $passed = ['Basic authentication & connection established'];
        $warnings = [];

        // Check information_schema access
        try {
            $connection->select('SELECT 1 FROM information_schema.TABLES LIMIT 1');
            $passed[] = 'Read access to information_schema.TABLES';
        } catch (Throwable $e) {
            return PermissionCheckResult::failure(
                errorMessage: 'Missing read access to information_schema: '.$e->getMessage(),
                passedChecks: $passed,
            );
        }

        // Check PROCESS privilege (activity / pool tracking)
        try {
            $connection->select('SHOW PROCESSLIST');
            $passed[] = 'PROCESS privilege (activity & pool tracking)';
        } catch (Throwable) {
            $warnings[] = 'Missing PROCESS privilege. Process list and connection pool tracking may be limited.';
        }

        // Check performance_schema digest access for slow queries
        try {
            $connection->select('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
            $passed[] = 'Read access to performance_schema slow query digest';
        } catch (Throwable) {
            $warnings[] = 'Missing SELECT on performance_schema.events_statements_summary_by_digest. Slow query analysis will be unavailable.';
        }

        return PermissionCheckResult::success(
            passedChecks: $passed,
            warnings: $warnings,
        );
    }

    /**
     * Check PostgreSQL specific permissions: pg_catalog, pg_stat_activity, pg_stat_statements.
     */
    protected function checkPgSql(ConnectionInterface $connection): PermissionCheckResult
    {
        $passed = ['Basic authentication & connection established'];
        $warnings = [];

        // Check pg_catalog access
        try {
            $connection->select('SELECT 1 FROM pg_catalog.pg_tables LIMIT 1');
            $passed[] = 'Read access to pg_catalog';
        } catch (Throwable $e) {
            return PermissionCheckResult::failure(
                errorMessage: 'Missing read access to pg_catalog: '.$e->getMessage(),
                passedChecks: $passed,
            );
        }

        // Check pg_stat_activity access
        try {
            $connection->select('SELECT count(*) FROM pg_stat_activity');
            $passed[] = 'Read access to pg_stat_activity';
        } catch (Throwable) {
            $warnings[] = 'Limited read access to pg_stat_activity. Consider granting pg_read_all_stats or pg_monitor.';
        }

        // Check pg_stat_statements extension
        try {
            $installed = $connection->select("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");
            if (empty($installed)) {
                $warnings[] = "Extension 'pg_stat_statements' is not installed. Slow query analysis requires this extension in PostgreSQL.";
            } else {
                $connection->select('SELECT 1 FROM pg_stat_statements LIMIT 1');
                $passed[] = 'Read access to pg_stat_statements';
            }
        } catch (Throwable) {
            $warnings[] = 'Cannot query pg_stat_statements. Consider granting pg_read_all_stats or installing the extension.';
        }

        return PermissionCheckResult::success(
            passedChecks: $passed,
            warnings: $warnings,
        );
    }
}
