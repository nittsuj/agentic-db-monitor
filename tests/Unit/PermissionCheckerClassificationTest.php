<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DbDriver;
use App\Enums\PrivilegeLevel;
use App\Services\Database\PermissionChecker;
use App\Services\Database\PermissionCheckResult;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PermissionCheckerClassificationTest extends TestCase
{
    protected PermissionChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new PermissionChecker(new TargetConnectionManager);
    }

    public function test_mysql_root_is_admin(): void
    {
        [$level] = $this->checker->classifyMySqlGrants('root@%', ['GRANT USAGE ON *.* TO `root`@`%`']);

        $this->assertSame(PrivilegeLevel::Admin, $level);
    }

    public function test_mysql_global_admin_privileges_are_admin(): void
    {
        $cases = [
            'GRANT ALL PRIVILEGES ON *.* TO `ops`@`%`',
            'GRANT SELECT, SUPER ON *.* TO `ops`@`%`',
            // RDS master users get broad global grants without SUPER.
            'GRANT SELECT, INSERT, UPDATE, CREATE USER ON *.* TO `admin`@`%` WITH GRANT OPTION',
            'GRANT SELECT ON *.* TO `ops`@`%` WITH GRANT OPTION',
        ];

        foreach ($cases as $grant) {
            [$level, $notes] = $this->checker->classifyMySqlGrants('ops@%', [$grant]);

            $this->assertSame(PrivilegeLevel::Admin, $level, $grant);
            $this->assertNotEmpty($notes);
        }
    }

    public function test_mysql_schema_writes_are_write(): void
    {
        [$level, $notes] = $this->checker->classifyMySqlGrants('app@%', [
            'GRANT USAGE ON *.* TO `app`@`%`',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`',
        ]);

        $this->assertSame(PrivilegeLevel::Write, $level);
        $this->assertSame(['INSERT, UPDATE, DELETE on `shop`.*.'], $notes);
    }

    public function test_mysql_monitoring_user_is_read_only(): void
    {
        [$level, $notes] = $this->checker->classifyMySqlGrants('adbm_ro@%', [
            'GRANT PROCESS ON *.* TO `adbm_ro`@`%`',
            'GRANT SELECT, SHOW VIEW ON `shop`.* TO `adbm_ro`@`%`',
            // Write grants on system schemas say nothing about client data.
            'GRANT SELECT, UPDATE ON `performance_schema`.* TO `adbm_ro`@`%`',
        ]);

        $this->assertSame(PrivilegeLevel::ReadOnly, $level);
        $this->assertSame([], $notes);
    }

    public function test_pg_superuser_and_provider_admin_roles_are_admin(): void
    {
        [$level] = $this->checker->classifyPgRole($this->role(['superuser' => true]));
        $this->assertSame(PrivilegeLevel::Admin, $level);

        [$level, $notes] = $this->checker->classifyPgRole($this->role(['admin_roles' => ['rds_superuser']]));
        $this->assertSame(PrivilegeLevel::Admin, $level);
        $this->assertSame(['Member of the provider admin role rds_superuser.'], $notes);

        // Supabase's "postgres" user is not a superuser but can create roles.
        [$level] = $this->checker->classifyPgRole($this->role(['create_role' => true]));
        $this->assertSame(PrivilegeLevel::Admin, $level);
    }

    public function test_pg_table_writes_are_write(): void
    {
        [$level, $notes] = $this->checker->classifyPgRole($this->role(['writable_tables' => 12]));

        $this->assertSame(PrivilegeLevel::Write, $level);
        $this->assertSame(['Can INSERT/UPDATE/DELETE on 12 table(s).'], $notes);
    }

    public function test_pg_plain_role_is_read_only_and_unreadable_role_is_unknown(): void
    {
        $this->assertSame(PrivilegeLevel::ReadOnly, $this->checker->classifyPgRole($this->role())[0]);
        $this->assertSame(PrivilegeLevel::Unknown, $this->checker->classifyPgRole(null)[0]);
    }

    public function test_an_empty_password_gets_a_plain_message(): void
    {
        $manager = new class extends TargetConnectionManager
        {
            public function connectTemporary(DbDriver $driver, string $host, int $port, string $database, string $username, string $password, array $options = []): ConnectionInterface
            {
                throw new RuntimeException('SQLSTATE[08006] [7] connection to server failed: fe_sendauth: no password supplied');
            }
        };

        $checker = new PermissionChecker($manager);

        $empty = $checker->check(DbDriver::PgSql, 'db', 5432, 'app', 'adbm_ro', '');
        $this->assertStringContainsString('Password database kosong', (string) $empty->errorMessage);
        $this->assertStringContainsString("'adbm_ro'", (string) $empty->errorMessage);

        // A typed password keeps the driver's own message.
        $typed = $checker->check(DbDriver::PgSql, 'db', 5432, 'app', 'adbm_ro', 'x');
        $this->assertStringStartsWith('Connection failed: ', (string) $typed->errorMessage);
    }

    public function test_review_is_needed_for_warnings_or_admin_only(): void
    {
        $this->assertFalse(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::ReadOnly)->needsReview());
        $this->assertFalse(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::Write)->needsReview());
        $this->assertTrue(PermissionCheckResult::success(privilegeLevel: PrivilegeLevel::Admin)->needsReview());
        $this->assertTrue(PermissionCheckResult::success(warnings: ['x'], privilegeLevel: PrivilegeLevel::ReadOnly)->needsReview());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{name: string, superuser: bool, create_role: bool, create_db: bool, bypass_rls: bool, admin_roles: list<string>, read_all_stats: bool, writable_tables: int}
     */
    protected function role(array $overrides = []): array
    {
        return array_merge([
            'name' => 'adbm_ro',
            'superuser' => false,
            'create_role' => false,
            'create_db' => false,
            'bypass_rls' => false,
            'admin_roles' => [],
            'read_all_stats' => true,
            'writable_tables' => 0,
        ], $overrides);
    }
}
