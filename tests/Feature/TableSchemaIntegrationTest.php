<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbDriver;
use App\Models\Project;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Runs get_table_schema against a real server. Each engine is skipped when its
 * server is not reachable; point the tests at one with TEST_PGSQL_* / TEST_MYSQL_*
 * (HOST, PORT, DATABASE, USERNAME, PASSWORD). The tables are created and dropped here.
 */
class TableSchemaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{DbDriver, string, int, string, string}>
     */
    public static function engines(): array
    {
        return [
            'postgres' => [DbDriver::PgSql, 'PGSQL', 5432, 'playground_pg', 'postgres'],
            'mysql' => [DbDriver::MySql, 'MYSQL', 3306, 'playground', 'root'],
        ];
    }

    #[DataProvider('engines')]
    public function test_it_reports_indexes_and_which_foreign_keys_have_none(
        DbDriver $engine, string $env, int $port, string $database, string $username,
    ): void {
        $project = Project::factory()->create([
            'driver' => $engine,
            'host' => env("TEST_{$env}_HOST", '127.0.0.1'),
            'port' => (int) env("TEST_{$env}_PORT", $port),
            'database' => env("TEST_{$env}_DATABASE", $database),
            'username' => env("TEST_{$env}_USERNAME", $username),
            'password' => env("TEST_{$env}_PASSWORD", 'secret'),
        ]);

        $manager = app(TargetConnectionManager::class);

        try {
            $connection = $manager->connect($project);
            $connection->select('SELECT 1');
        } catch (Throwable $e) {
            $manager->purge($manager->connectionName($project));
            $this->markTestSkipped("{$engine->value} not available: {$e->getMessage()}");
        }

        try {
            $this->createTables($connection);

            $schema = DiagnosticTools::driverFor($engine->value)->getTableSchema($connection, 'zz_schema_child');
        } finally {
            $this->dropTables($connection);
            $manager->purge($manager->connectionName($project));
        }

        $this->assertSame(['id', 'parent_id', 'other_id', 'note'], array_column($schema['columns'], 'name'));

        $indexes = array_column($schema['indexes'], 'columns', 'name');
        $this->assertContains(['parent_id', 'note'], $indexes);
        $this->assertContains(['id'], $indexes);

        $byColumn = [];
        foreach ($schema['foreign_keys'] as $key) {
            $byColumn[implode(',', $key['columns'])] = $key;
        }

        $this->assertTrue($byColumn['parent_id']['indexed']);
        $this->assertSame(['table' => 'zz_schema_parent', 'columns' => ['id']], $byColumn['parent_id']['references']);

        if ($engine === DbDriver::MySql) {
            // InnoDB creates an index for every foreign key that lacks one.
            $this->assertTrue($byColumn['other_id']['indexed']);
            $this->assertSame([], $schema['unindexed_foreign_keys']);
        } else {
            $this->assertFalse($byColumn['other_id']['indexed']);
            $this->assertSame(['other_id'], $schema['unindexed_foreign_keys']);
        }
    }

    private function createTables(ConnectionInterface $connection): void
    {
        $this->dropTables($connection);

        $connection->statement('CREATE TABLE zz_schema_parent (id INT PRIMARY KEY)');
        $connection->statement('CREATE TABLE zz_schema_child (
            id INT PRIMARY KEY,
            parent_id INT NOT NULL,
            other_id INT,
            note VARCHAR(50),
            CONSTRAINT zz_schema_child_parent_fk FOREIGN KEY (parent_id) REFERENCES zz_schema_parent (id),
            CONSTRAINT zz_schema_child_other_fk FOREIGN KEY (other_id) REFERENCES zz_schema_parent (id)
        )');
        $connection->statement('CREATE INDEX zz_schema_child_parent_note ON zz_schema_child (parent_id, note)');
    }

    private function dropTables(ConnectionInterface $connection): void
    {
        $connection->statement('DROP TABLE IF EXISTS zz_schema_child');
        $connection->statement('DROP TABLE IF EXISTS zz_schema_parent');
    }
}
