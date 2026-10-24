<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\DbDriver;
use App\Enums\PrivilegeLevel;
use Illuminate\Database\ConnectionInterface;
use Throwable;

class PermissionChecker
{
    /**
     * Roles that managed providers give their "master" user in place of a real
     * superuser. Membership means the user can create roles and change grants.
     */
    private const PG_ADMIN_ROLES = ['rds_superuser', 'cloudsqlsuperuser', 'azure_pg_admin', 'supabase_admin'];

    /**
     * Global MySQL privileges that let a user administer the server or other accounts.
     */
    private const MYSQL_ADMIN_PRIVILEGES = ['ALL PRIVILEGES', 'SUPER', 'CREATE USER', 'GRANT OPTION', 'SYSTEM_USER', 'SHUTDOWN', 'RELOAD', 'FILE'];

    private const MYSQL_WRITE_PRIVILEGES = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'INDEX', 'TRIGGER', 'EXECUTE'];

    /**
     * Schemas whose grants say nothing about the client's data.
     */
    private const MYSQL_SYSTEM_SCHEMAS = ['performance_schema', 'information_schema', 'sys'];

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
            if ($password === '' && str_contains($e->getMessage(), 'no password supplied')) {
                return PermissionCheckResult::failure(
                    errorMessage: "Password database kosong. Server meminta password untuk user '{$username}'; isi kolom Password lalu coba lagi.",
                );
            }

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

        // performance_schema can be switched off server-wide; that needs a
        // config change and restart, not a grant, so say which one it is.
        $enabled = $this->scalar($connection, 'SELECT @@performance_schema AS v');

        if ($enabled !== null && (int) $enabled === 0) {
            $warnings[] = 'performance_schema is disabled on this server (performance_schema=OFF). Slow query analysis needs it enabled in the server config or parameter group, followed by a restart.';
        } else {
            try {
                $connection->select('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
                $passed[] = 'Read access to performance_schema slow query digest';

                $consumer = $this->scalar(
                    $connection,
                    "SELECT ENABLED AS v FROM performance_schema.setup_consumers WHERE NAME = 'statements_digest'",
                );

                if ($consumer !== null && strtoupper((string) $consumer) !== 'YES') {
                    $warnings[] = "performance_schema consumer 'statements_digest' is disabled, so no query digests are recorded. Enable it in setup_consumers.";
                }
            } catch (Throwable) {
                $warnings[] = 'Missing SELECT on performance_schema.events_statements_summary_by_digest. Slow query analysis will be unavailable. Run: GRANT SELECT ON performance_schema.* TO <user>;';
            }
        }

        $currentUser = $this->scalar($connection, 'SELECT CURRENT_USER() AS v');

        try {
            $grants = array_map(
                fn (object $row): string => (string) array_values((array) $row)[0],
                $connection->select('SHOW GRANTS'),
            );
            [$level, $notes] = $this->classifyMySqlGrants((string) $currentUser, $grants);
        } catch (Throwable) {
            [$level, $notes] = [PrivilegeLevel::Unknown, ['SHOW GRANTS failed, so the privilege level could not be checked.']];
        }

        return PermissionCheckResult::success(
            passedChecks: $passed,
            warnings: $warnings,
            privilegeLevel: $level,
            privilegeNotes: $notes,
            currentUser: $currentUser === null ? null : (string) $currentUser,
        );
    }

    /**
     * Classify a MySQL account from its SHOW GRANTS lines. Privileges reached
     * only through roles that are not active are not listed there.
     *
     * @param  list<string>  $grants
     * @return array{0: PrivilegeLevel, 1: list<string>}
     */
    public function classifyMySqlGrants(string $currentUser, array $grants): array
    {
        $account = strtolower(trim(explode('@', $currentUser)[0], "`'\" "));

        if ($account === 'root') {
            return [PrivilegeLevel::Admin, ["Connected as 'root'."]];
        }

        $notes = [];
        $write = false;

        foreach ($grants as $grant) {
            if (! preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', $grant, $match)) {
                continue;
            }

            $privileges = array_map(fn (string $p): string => strtoupper(trim($p)), explode(',', $match[1]));
            $target = str_replace(['`', '"', "'"], '', strtolower($match[2]));
            $schema = explode('.', $target)[0];
            $global = $target === '*.*';
            $withGrantOption = (bool) preg_match('/WITH\s+GRANT\s+OPTION/i', $grant);

            if ($global) {
                $admin = array_values(array_intersect($privileges, self::MYSQL_ADMIN_PRIVILEGES));

                if ($admin !== [] || $withGrantOption) {
                    $label = $admin !== [] ? implode(', ', $admin) : 'WITH GRANT OPTION';

                    return [PrivilegeLevel::Admin, ["Global privilege on *.*: {$label}."]];
                }
            }

            if (in_array($schema, self::MYSQL_SYSTEM_SCHEMAS, true)) {
                continue;
            }

            $writes = array_values(array_intersect($privileges, [...self::MYSQL_WRITE_PRIVILEGES, 'ALL PRIVILEGES', 'ALL']));

            if ($writes !== []) {
                $write = true;
                $notes[] = implode(', ', $writes)." on {$match[2]}.";
            }
        }

        return $write ? [PrivilegeLevel::Write, $notes] : [PrivilegeLevel::ReadOnly, []];
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

        $role = $this->pgRole($connection);
        [$level, $notes] = $this->classifyPgRole($role);

        // Check pg_stat_activity access
        try {
            $connection->select('SELECT count(*) FROM pg_stat_activity');
            $passed[] = 'Read access to pg_stat_activity';
        } catch (Throwable) {
            $warnings[] = 'Limited read access to pg_stat_activity. Consider granting pg_read_all_stats or pg_monitor.';
        }

        // Without pg_read_all_stats, other sessions' query text shows as
        // "<insufficient privilege>", which hides most of what we diagnose.
        if ($role !== null && ! $role['superuser'] && ! $role['read_all_stats']) {
            $warnings[] = 'The user is not a member of pg_read_all_stats (or pg_monitor), so queries from other users are hidden. Run: GRANT pg_monitor TO '.$role['name'].';';
        }

        $warnings = [...$warnings, ...$this->checkPgStatStatements($connection, $passed)];

        return PermissionCheckResult::success(
            passedChecks: $passed,
            warnings: $warnings,
            privilegeLevel: $level,
            privilegeNotes: $notes,
            currentUser: $role['name'] ?? null,
        );
    }

    /**
     * pg_stat_statements fails in three distinct ways: not available on the
     * server, available but not created in this database, or created but not
     * preloaded. Each needs a different fix.
     *
     * @param  list<string>  $passed
     * @return list<string>
     */
    protected function checkPgStatStatements(ConnectionInterface $connection, array &$passed): array
    {
        try {
            $installed = $connection->select("SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'");

            if (empty($installed)) {
                $available = $connection->select("SELECT 1 FROM pg_available_extensions WHERE name = 'pg_stat_statements'");

                return [empty($available)
                    ? "Extension 'pg_stat_statements' is not available on this server. Slow query analysis requires it."
                    : "Extension 'pg_stat_statements' is not installed in this database. Slow query analysis requires it. Run: CREATE EXTENSION pg_stat_statements;"];
            }

            $connection->select('SELECT 1 FROM pg_stat_statements LIMIT 1');
            $passed[] = 'Read access to pg_stat_statements';

            return [];
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'shared_preload_libraries')) {
                return ["Extension 'pg_stat_statements' is installed but not loaded. Add it to shared_preload_libraries (or the provider's parameter group) and restart the server."];
            }

            return ['Cannot query pg_stat_statements. Consider granting pg_read_all_stats or pg_monitor.'];
        }
    }

    /**
     * @return array{name: string, superuser: bool, create_role: bool, create_db: bool, bypass_rls: bool, admin_roles: list<string>, read_all_stats: bool, writable_tables: int}|null
     */
    protected function pgRole(ConnectionInterface $connection): ?array
    {
        try {
            $row = $connection->selectOne(
                "SELECT r.rolname AS name, r.rolsuper AS superuser, r.rolcreaterole AS create_role,
                        r.rolcreatedb AS create_db, r.rolbypassrls AS bypass_rls,
                        pg_has_role(current_user, 'pg_read_all_stats', 'USAGE') AS read_all_stats
                   FROM pg_roles r WHERE r.rolname = current_user"
            );
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        // The oid form of pg_has_role never errors on a role this server lacks.
        try {
            $placeholders = implode(', ', array_fill(0, count(self::PG_ADMIN_ROLES), '?'));
            $adminRoles = array_map(
                fn (object $r): string => (string) $r->rolname,
                $connection->select(
                    "SELECT rolname FROM pg_roles WHERE rolname IN ({$placeholders}) AND pg_has_role(current_user, oid, 'MEMBER')",
                    self::PG_ADMIN_ROLES,
                ),
            );
        } catch (Throwable) {
            $adminRoles = [];
        }

        try {
            $writable = (int) ($connection->selectOne(
                "SELECT count(*) AS v FROM pg_tables
                  WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
                    AND has_table_privilege(format('%I.%I', schemaname, tablename), 'INSERT, UPDATE, DELETE, TRUNCATE')"
            )?->v ?? 0);
        } catch (Throwable) {
            $writable = 0;
        }

        return [
            'name' => (string) $row->name,
            'superuser' => $this->bool($row->superuser),
            'create_role' => $this->bool($row->create_role),
            'create_db' => $this->bool($row->create_db),
            'bypass_rls' => $this->bool($row->bypass_rls),
            'admin_roles' => $adminRoles,
            'read_all_stats' => $this->bool($row->read_all_stats),
            'writable_tables' => $writable,
        ];
    }

    /**
     * @param  array{name: string, superuser: bool, create_role: bool, create_db: bool, bypass_rls: bool, admin_roles: list<string>, read_all_stats: bool, writable_tables: int}|null  $role
     * @return array{0: PrivilegeLevel, 1: list<string>}
     */
    public function classifyPgRole(?array $role): array
    {
        if ($role === null) {
            return [PrivilegeLevel::Unknown, ['The role attributes could not be read.']];
        }

        $admin = [];

        if ($role['superuser']) {
            $admin[] = "'{$role['name']}' is a superuser.";
        }

        foreach ($role['admin_roles'] as $adminRole) {
            $admin[] = "Member of the provider admin role {$adminRole}.";
        }

        if ($role['create_role']) {
            $admin[] = 'Has CREATEROLE.';
        }

        if ($role['bypass_rls']) {
            $admin[] = 'Has BYPASSRLS.';
        }

        if ($admin !== []) {
            return [PrivilegeLevel::Admin, $admin];
        }

        $write = [];

        if ($role['writable_tables'] > 0) {
            $write[] = "Can INSERT/UPDATE/DELETE on {$role['writable_tables']} table(s).";
        }

        if ($role['create_db']) {
            $write[] = 'Has CREATEDB.';
        }

        return $write !== [] ? [PrivilegeLevel::Write, $write] : [PrivilegeLevel::ReadOnly, []];
    }

    protected function scalar(ConnectionInterface $connection, string $sql): mixed
    {
        try {
            return $connection->selectOne($sql)?->v;
        } catch (Throwable) {
            return null;
        }
    }

    protected function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
