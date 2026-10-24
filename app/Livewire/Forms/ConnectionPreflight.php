<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\DbDriver;
use App\Enums\PrivilegeLevel;
use App\Services\Database\PermissionCheckResult;

/**
 * Pre-flight result shown by the create and edit project forms: passed
 * checks, missing monitoring features, and the privilege level of the user.
 */
trait ConnectionPreflight
{
    /**
     * @var list<string>
     */
    public array $passedChecks = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    public ?string $privilegeLevel = null;

    /**
     * @var list<string>
     */
    public array $privilegeNotes = [];

    public ?string $checkedUser = null;

    protected function applyPreflight(PermissionCheckResult $result): void
    {
        $this->passedChecks = $result->passedChecks;
        $this->warnings = $result->warnings;
        $this->privilegeLevel = $result->successful ? $result->privilegeLevel->value : null;
        $this->privilegeNotes = $result->privilegeNotes;
        $this->checkedUser = $result->currentUser;
    }

    protected function clearPreflight(): void
    {
        $this->passedChecks = [];
        $this->warnings = [];
        $this->privilegeLevel = null;
        $this->privilegeNotes = [];
        $this->checkedUser = null;
    }

    /**
     * SQL that creates a dedicated monitoring user with only what the
     * diagnostics read, for the database being connected.
     */
    public function readOnlyRoleSql(): string
    {
        $database = $this->database !== '' ? $this->database : 'mydb';

        return match (DbDriver::tryFrom($this->driver)) {
            DbDriver::PgSql => implode("\n", [
                "CREATE ROLE adbm_ro LOGIN PASSWORD '<password>';",
                "GRANT CONNECT ON DATABASE \"{$database}\" TO adbm_ro;",
                'GRANT pg_monitor TO adbm_ro;',
                'GRANT USAGE ON SCHEMA public TO adbm_ro;',
                'GRANT SELECT ON ALL TABLES IN SCHEMA public TO adbm_ro;',
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO adbm_ro;',
                'CREATE EXTENSION IF NOT EXISTS pg_stat_statements;',
            ]),
            default => implode("\n", [
                "CREATE USER 'adbm_ro'@'%' IDENTIFIED BY '<password>';",
                "GRANT SELECT, SHOW VIEW ON `{$database}`.* TO 'adbm_ro'@'%';",
                "GRANT PROCESS ON *.* TO 'adbm_ro'@'%';",
                "GRANT SELECT ON performance_schema.* TO 'adbm_ro'@'%';",
            ]),
        };
    }

    public function isAdminConnection(): bool
    {
        return $this->privilegeLevel === PrivilegeLevel::Admin->value;
    }
}
