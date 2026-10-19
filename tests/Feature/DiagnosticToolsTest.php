<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DbDriver;
use App\Models\Project;
use App\Services\Database\DiagnosticTools;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnosticToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_driver_lists_tables(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'playground',
            'username' => 'root',
            'password' => 'secret',
        ]);

        $manager = app(TargetConnectionManager::class);
        $driver = DiagnosticTools::driverFor('mysql');

        try {
            $connection = $manager->connect($project);
            $tables = $driver->listTables($connection);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: '.$e->getMessage());
        } finally {
            $manager->purge($manager->connectionName($project));
        }

        $this->assertIsArray($tables);

        $tools = DiagnosticTools::for($connection, $driver);
        $this->assertCount(5, $tools);
    }

    public function test_postgres_driver_lists_tables(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'playground_pg',
            'username' => 'postgres',
            'password' => 'secret',
        ]);

        $manager = app(TargetConnectionManager::class);
        $driver = DiagnosticTools::driverFor('pgsql');

        try {
            $connection = $manager->connect($project);
            $tables = $driver->listTables($connection);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres not available: '.$e->getMessage());
        } finally {
            $manager->purge($manager->connectionName($project));
        }

        $this->assertIsArray($tables);
    }
}
