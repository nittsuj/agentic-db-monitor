<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\Diagnostics\DiagnosticDriver;
use App\Services\Database\DiagnosticTools;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;

class DiagnosticToolsOutputTest extends TestCase
{
    public function test_every_tool_returns_json_text_instead_of_an_array(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $driver = new class implements DiagnosticDriver
        {
            public function listTables(ConnectionInterface $connection): array
            {
                return ['users', 'events'];
            }

            public function getTableSchema(ConnectionInterface $connection, string $table): array
            {
                return [['name' => 'id', 'type' => 'bigint']];
            }

            public function getSlowQueries(ConnectionInterface $connection, int $limit = 10): array
            {
                return [['query' => 'SELECT * FROM users', 'limit' => $limit]];
            }

            public function checkConnectionPool(ConnectionInterface $connection): array
            {
                return ['threads_connected' => 3, 'max_connections' => 100];
            }

            public function analyzeTableBloat(ConnectionInterface $connection): array
            {
                return [['table_name' => 'events', 'free_mb' => 12.5]];
            }

            public function collectMetrics(ConnectionInterface $connection): array
            {
                return ['connection_pool_used' => 3.0];
            }
        };

        $tools = [];

        foreach (DiagnosticTools::for($connection, $driver) as $tool) {
            $tools[$tool->name()] = $tool;
        }

        $this->assertSame(['users', 'events'], $this->decode($tools['list_tables']->handle()));
        $this->assertSame([['name' => 'id', 'type' => 'bigint']], $this->decode($tools['get_table_schema']->handle(table: 'users')));
        $this->assertSame([['query' => 'SELECT * FROM users', 'limit' => 5]], $this->decode($tools['get_slow_queries']->handle(limit: 5)));
        $this->assertSame(['threads_connected' => 3, 'max_connections' => 100], $this->decode($tools['check_connection_pool']->handle()));
        $this->assertSame([['table_name' => 'events', 'free_mb' => 12.5]], $this->decode($tools['analyze_table_bloat']->handle()));
    }

    private function decode(string|object $output): mixed
    {
        $this->assertIsString($output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
