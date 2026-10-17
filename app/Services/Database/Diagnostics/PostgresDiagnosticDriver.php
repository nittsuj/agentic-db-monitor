<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

use Illuminate\Database\ConnectionInterface;

class PostgresDiagnosticDriver implements DiagnosticDriver
{
    public function listTables(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
        );

        return array_map(fn ($r) => (string) $r->name, $rows);
    }

    public function getTableSchema(ConnectionInterface $connection, string $table): array
    {
        return $connection->select(
            "SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS default_value
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );
    }

    public function getSlowQueries(ConnectionInterface $connection, int $limit = 10): array
    {
        return $connection->select(
            'SELECT query, calls, ROUND(total_exec_time::numeric, 2) AS total_time_ms, ROUND(mean_exec_time::numeric, 2) AS mean_time_ms, rows
             FROM pg_stat_statements
             ORDER BY total_exec_time DESC
             LIMIT ?',
            [$limit]
        );
    }

    public function checkConnectionPool(ConnectionInterface $connection): array
    {
        $active = $connection->selectOne('SELECT COUNT(*) AS c FROM pg_stat_activity');
        $max = $connection->selectOne('SHOW max_connections');
        $maxVal = is_object($max) ? (int) ($max->max_connections ?? $max->setting ?? 0) : 0;

        return [
            'active_connections' => (int) ($active->c ?? 0),
            'max_connections' => $maxVal,
            'idle_connections' => (int) $connection->selectOne("SELECT COUNT(*) AS c FROM pg_stat_activity WHERE state = 'idle'")->c,
        ];
    }

    public function analyzeTableBloat(ConnectionInterface $connection): array
    {
        return $connection->select(
            'SELECT schemaname, relname AS table_name, n_live_tup AS live_rows, n_dead_tup AS dead_rows,
                    ROUND((n_dead_tup::numeric / NULLIF(n_live_tup + n_dead_tup, 0)) * 100, 2) AS dead_pct
             FROM pg_stat_user_tables
             ORDER BY n_dead_tup DESC
             LIMIT 20'
        );
    }
}
