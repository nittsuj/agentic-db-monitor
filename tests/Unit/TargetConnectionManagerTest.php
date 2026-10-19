<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DbDriver;
use App\Models\Project;
use App\Services\Database\TargetConnectionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TargetConnectionManagerTest extends TestCase
{
    use RefreshDatabase;

    protected TargetConnectionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TargetConnectionManager;
    }

    public function test_connection_name_generation(): void
    {
        $project = Project::factory()->create();

        $this->assertSame("target_{$project->id}", $this->manager->connectionName($project));
        $this->assertSame('target_sample-uuid', $this->manager->connectionName('sample-uuid'));
    }

    public function test_connect_registers_dynamic_mysql_connection(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'secret123',
        ]);

        $connection = $this->manager->connect($project);
        $connName = $this->manager->connectionName($project);

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertSame('mysql', Config::get("database.connections.{$connName}.driver"));
        $this->assertSame('test_db', Config::get("database.connections.{$connName}.database"));
        $this->assertSame('test_user', Config::get("database.connections.{$connName}.username"));
        $this->assertSame('secret123', Config::get("database.connections.{$connName}.password"));

        $this->manager->purge($connName);
        $this->assertNull(Config::get("database.connections.{$connName}"));
    }

    public function test_connect_registers_dynamic_pgsql_connection(): void
    {
        $project = Project::factory()->create([
            'driver' => DbDriver::PgSql,
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'pg_db',
            'username' => 'pg_user',
            'password' => 'pg_secret',
        ]);

        $connection = $this->manager->connect($project);
        $connName = $this->manager->connectionName($project);

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertSame('pgsql', Config::get("database.connections.{$connName}.driver"));
        $this->assertSame('pg_db', Config::get("database.connections.{$connName}.database"));
        $this->assertSame('pg_user', Config::get("database.connections.{$connName}.username"));
        $this->assertSame('pg_secret', Config::get("database.connections.{$connName}.password"));
        // libpq must give up quickly on an unreachable host instead of hanging.
        $this->assertSame('5', getenv('PGCONNECT_TIMEOUT'));

        $this->manager->purge($connName);
    }
}
