<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

use Illuminate\Database\ConnectionInterface;

class MySqlDiagnosticDriver implements DiagnosticDriver
{
    public function listTables(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );

        return array_map(fn ($r) => (string) $r->name, $rows);
    }

    public function getTableSchema(ConnectionInterface $connection, string $table): array
    {
        return $connection->select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, COLUMN_KEY AS key_type, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$table]
        );
    }

    public function getSlowQueries(ConnectionInterface $connection, int $limit = 10): array
    {
        return $connection->select(
            'SELECT DIGEST_TEXT AS query, COUNT_STAR AS exec_count, ROUND(SUM_TIMER_WAIT/1e12,3) AS total_time_sec, ROUND(AVG_TIMER_WAIT/1e9,3) AS avg_time_ms, SUM_ROWS_EXAMINED AS rows_examined, SUM_ROWS_SENT AS rows_sent
             FROM performance_schema.events_statements_summary_by_digest
             WHERE DIGEST_TEXT IS NOT NULL
             ORDER BY SUM_TIMER_WAIT DESC
             LIMIT ?',
            [$limit]
        );
    }

    public function checkConnectionPool(ConnectionInterface $connection): array
    {
        $status = $connection->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Connections')");
        $variables = $connection->select("SHOW VARIABLES WHERE Variable_name IN ('max_connections','thread_cache_size')");

        $map = [];
        foreach (array_merge($status, $variables) as $row) {
            $map[$row->Variable_name] = $row->Value;
        }

        return [
            'threads_connected' => (int) ($map['Threads_connected'] ?? 0),
            'threads_running' => (int) ($map['Threads_running'] ?? 0),
            'max_used_connections' => (int) ($map['Max_used_connections'] ?? 0),
            'max_connections' => (int) ($map['max_connections'] ?? 0),
            'connections_total' => (int) ($map['Connections'] ?? 0),
        ];
    }

    public function analyzeTableBloat(ConnectionInterface $connection): array
    {
        return $connection->select(
            "SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS estimated_rows,
                    ROUND(DATA_LENGTH/1024/1024,2) AS data_mb, ROUND(INDEX_LENGTH/1024/1024,2) AS index_mb,
                    ROUND(DATA_FREE/1024/1024,2) AS free_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY DATA_FREE DESC
             LIMIT 20"
        );
    }

    public function collectMetrics(ConnectionInterface $connection): array
    {
        $pool = $this->checkConnectionPool($connection);

        $rows = $connection->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Slow_queries','Uptime')");
        $status = [];
        foreach ($rows as $row) {
            $status[$row->Variable_name] = $row->Value;
        }

        $uptimeMinutes = max(1.0, ((float) ($status['Uptime'] ?? 0)) / 60);

        return [
            'connection_pool_used' => $pool['max_connections'] > 0
                ? round(100 * $pool['threads_connected'] / $pool['max_connections'], 2)
                : 0.0,
            'slow_queries_per_min' => round(((float) ($status['Slow_queries'] ?? 0)) / $uptimeMinutes, 3),
        ];
    }
}
