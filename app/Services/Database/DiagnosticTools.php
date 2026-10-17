<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Services\Database\Diagnostics\DiagnosticDriver;
use App\Services\Database\Diagnostics\MySqlDiagnosticDriver;
use App\Services\Database\Diagnostics\PostgresDiagnosticDriver;
use Illuminate\Database\ConnectionInterface;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

class DiagnosticTools
{
    public static function for(ConnectionInterface $connection, DiagnosticDriver $driver): array
    {
        return [
            (new Tool)
                ->as('list_tables')
                ->for('List all user tables in the target database. Use this first for discovery.')
                ->using(fn (): array => $driver->listTables($connection)),

            (new Tool)
                ->as('get_table_schema')
                ->for('Get column definitions for a known table. Only call for tables returned by list_tables. Max 2-3 calls per request.')
                ->withParameter(new StringSchema('table', 'Exact table name from list_tables'))
                ->using(fn (string $table): array => $driver->getTableSchema($connection, $table)),

            (new Tool)
                ->as('get_slow_queries')
                ->for('Return the slowest queries by total execution time.')
                ->withParameter(new NumberSchema('limit', 'Max rows to return (default 10)', minimum: 1, maximum: 50))
                ->using(fn (int $limit = 10): array => $driver->getSlowQueries($connection, $limit)),

            (new Tool)
                ->as('check_connection_pool')
                ->for('Return current connection pool / activity statistics.')
                ->using(fn (): array => $driver->checkConnectionPool($connection)),

            (new Tool)
                ->as('analyze_table_bloat')
                ->for('Return table size and bloat estimates (dead rows / free space).')
                ->using(fn (): array => $driver->analyzeTableBloat($connection)),
        ];
    }

    public static function driverFor(string $engine): DiagnosticDriver
    {
        return $engine === 'pgsql' ? new PostgresDiagnosticDriver : new MySqlDiagnosticDriver;
    }
}
