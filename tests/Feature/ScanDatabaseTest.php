<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbDriver;
use App\Enums\ScanStatus;
use App\Jobs\ScanDatabase;
use App\Models\Project;
use App\Models\ScanRun;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The happy path runs against a real MySQL server and is skipped when none is
 * reachable; point it at one with TEST_MYSQL_* (HOST, PORT, DATABASE,
 * USERNAME, PASSWORD), as for TableSchemaIntegrationTest.
 */
class ScanDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_scans_every_table_and_records_what_it_finds(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => env('TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('TEST_MYSQL_PORT', 3306),
            'database' => env('TEST_MYSQL_DATABASE', 'playground'),
            'username' => env('TEST_MYSQL_USERNAME', 'root'),
            'password' => env('TEST_MYSQL_PASSWORD', 'secret'),
        ]);

        $manager = app(TargetConnectionManager::class);

        try {
            $connection = $manager->connect($project);
            $connection->select('SELECT 1');
        } catch (Throwable $e) {
            $manager->purge($manager->connectionName($project));
            $this->markTestSkipped("mysql not available: {$e->getMessage()}");
        }

        $tables = ['zz_scan_parent', 'zz_scan_child', 'zz_scan_log'];

        try {
            $this->createTables($connection);
            $manager->purge($manager->connectionName($project));

            $run = ScanRun::factory()->for($project)->create();
            (new ScanDatabase($run->id))->handle($manager);
            $run->refresh();
        } finally {
            $connection = $manager->connect($project);
            foreach (array_reverse($tables) as $table) {
                $connection->statement("DROP TABLE IF EXISTS {$table}");
            }
            $manager->purge($manager->connectionName($project));
        }

        $this->assertSame(ScanStatus::Done, $run->status);
        $this->assertSame($run->total, $run->processed);
        $this->assertGreaterThanOrEqual(3, $run->total);
        $this->assertSame(100, $run->percent());
        $this->assertNull($run->current_table);
        $this->assertNotNull($run->finished_at);

        $findings = collect($run->findings)->keyBy('table');
        $this->assertSame(['Tidak punya primary key'], $findings['zz_scan_log']['issues'] ?? null);
        $this->assertFalse($findings->has('zz_scan_parent'));
        $this->assertFalse($findings->has('zz_scan_child'));
    }

    public function test_a_database_that_cannot_be_reached_fails_the_scan_with_a_reason(): void
    {
        $run = ScanRun::factory()->create();

        $manager = Mockery::mock(TargetConnectionManager::class);
        $manager->shouldReceive('connectionName')->andReturn('target_x');
        $manager->shouldReceive('connect')->andThrow(new RuntimeException('Connection refused'));
        $manager->shouldReceive('purge')->once()->with('target_x');

        (new ScanDatabase($run->id))->handle($manager);
        $run->refresh();

        $this->assertSame(ScanStatus::Failed, $run->status);
        $this->assertSame('Connection refused', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_finished_scan_is_not_run_again(): void
    {
        $run = ScanRun::factory()->create(['status' => ScanStatus::Done]);

        $manager = Mockery::mock(TargetConnectionManager::class);
        $manager->shouldNotReceive('connect');

        (new ScanDatabase($run->id))->handle($manager);

        $this->assertSame(ScanStatus::Done, $run->fresh()->status);
    }

    public function test_a_job_that_dies_outside_handle_marks_the_scan_failed(): void
    {
        $run = ScanRun::factory()->create(['status' => ScanStatus::Running]);

        (new ScanDatabase($run->id))->failed(new RuntimeException('Job timed out'));

        $this->assertSame(ScanStatus::Failed, $run->fresh()->status);
        $this->assertSame('Job timed out', $run->fresh()->error);
    }

    private function createTables(ConnectionInterface $connection): void
    {
        $connection->statement('DROP TABLE IF EXISTS zz_scan_log');
        $connection->statement('DROP TABLE IF EXISTS zz_scan_child');
        $connection->statement('DROP TABLE IF EXISTS zz_scan_parent');
        $connection->statement('CREATE TABLE zz_scan_parent (id INT PRIMARY KEY)');
        // InnoDB always indexes a foreign key, so on MySQL this one must not be
        // reported. (Unindexed keys are a PostgreSQL issue; SchemaFindingsTest
        // covers how they are worded.)
        $connection->statement('CREATE TABLE zz_scan_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES zz_scan_parent (id))');
        $connection->statement('CREATE TABLE zz_scan_log (message VARCHAR(50))');
    }
}
