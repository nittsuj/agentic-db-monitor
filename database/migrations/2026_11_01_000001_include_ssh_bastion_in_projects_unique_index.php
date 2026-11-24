<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Through an SSH tunnel the database host is resolved by the bastion, so the
 * same host/port/database/username behind two different bastions is two
 * different databases. The unique index therefore also covers the bastion
 * (SSH host, port and username) when SSH is enabled, and an empty value when
 * it is not. Project::sshIdentity() computes the same value in PHP.
 */
return new class extends Migration
{
    protected const INDEX = 'projects_connection_unique';

    public function up(): void
    {
        $bastion = match (DB::getDriverName()) {
            'sqlite' => <<<'SQL'
                CASE WHEN json_type(options, '$.ssh.enabled') = 'true'
                    THEN COALESCE(json_extract(options, '$.ssh.host'), '')
                        || ':' || COALESCE(json_extract(options, '$.ssh.port'), '')
                        || ':' || COALESCE(json_extract(options, '$.ssh.username'), '')
                    ELSE '' END
                SQL,
            'pgsql' => <<<'SQL'
                CASE WHEN (options -> 'ssh' ->> 'enabled') = 'true'
                    THEN COALESCE(options -> 'ssh' ->> 'host', '')
                        || ':' || COALESCE(options -> 'ssh' ->> 'port', '')
                        || ':' || COALESCE(options -> 'ssh' ->> 'username', '')
                    ELSE '' END
                SQL,
            // MySQL indexes an expression only through a functional key part
            // (8.0.13+), which must have a bounded type: 330 characters hold
            // the longest host (253), port (5) and user name (64) the forms accept.
            'mysql', 'mariadb' => <<<'SQL'
                CAST(CASE WHEN JSON_EXTRACT(options, '$.ssh.enabled') = CAST('true' AS JSON)
                    THEN CONCAT(
                        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(options, '$.ssh.host')), ''),
                        ':', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(options, '$.ssh.port')), ''),
                        ':', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(options, '$.ssh.username')), ''))
                    ELSE '' END AS CHAR(330))
                SQL,
            default => throw new RuntimeException('The projects unique index supports SQLite, PostgreSQL and MySQL only.'),
        };

        // "database" is a reserved word; let the driver quote it.
        $database = DB::getQueryGrammar()->wrap('database');

        // Create the new index before dropping the old one: SQLite does not
        // undo a partly applied migration, so a failure must leave the table
        // with an index, never without one.
        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON projects (driver, host, port, '.$database.', username, ('.$bastion.'))');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['driver', 'host', 'port', 'database', 'username']);
        });
    }

    /**
     * Fails, and changes nothing, if profiles that differ only by bastion (or
     * by SSH being on or off) exist by then; remove or change those first.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unique(['driver', 'host', 'port', 'database', 'username']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
