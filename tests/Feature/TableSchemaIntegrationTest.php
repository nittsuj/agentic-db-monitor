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

    /**
     * analyze_table_bloat keeps 20 tables; they must be the largest ones, or the
     * assistant cannot see which table is biggest in a database with many tables.
     */
    #[DataProvider('engines')]
    public function test_the_bloat_report_lists_the_largest_tables_first(
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
            // 21 small tables with dead rows (the old sort key), then one big table without any.
            for ($i = 1; $i <= 21; $i++) {
                $connection->statement("DROP TABLE IF EXISTS zz_bloat_small_{$i}");
                $connection->statement("CREATE TABLE zz_bloat_small_{$i} (id INT PRIMARY KEY)");
                $connection->statement("INSERT INTO zz_bloat_small_{$i} VALUES (1), (2)");
                $connection->statement("DELETE FROM zz_bloat_small_{$i} WHERE id = 1");
            }
            $connection->statement('DROP TABLE IF EXISTS zz_bloat_big');
            $connection->statement('CREATE TABLE zz_bloat_big (id INT PRIMARY KEY, payload VARCHAR(200))');
            for ($chunk = 0; $chunk < 20; $chunk++) {
                $values = implode(',', array_map(
                    fn (int $n): string => '('.($chunk * 500 + $n).", '".str_repeat('x', 200)."')",
                    range(1, 500),
                ));
                $connection->statement("INSERT INTO zz_bloat_big VALUES {$values}");
            }
            $connection->statement($engine === DbDriver::MySql ? 'ANALYZE TABLE zz_bloat_big' : 'ANALYZE zz_bloat_big');

            $rows = DiagnosticTools::driverFor($engine->value)->analyzeTableBloat($connection);
        } finally {
            for ($i = 1; $i <= 21; $i++) {
                $connection->statement("DROP TABLE IF EXISTS zz_bloat_small_{$i}");
            }
            $connection->statement('DROP TABLE IF EXISTS zz_bloat_big');
            $manager->purge($manager->connectionName($project));
        }

        $this->assertCount(20, $rows);
        $this->assertSame('zz_bloat_big', ((array) $rows[0])['table_name']);
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
