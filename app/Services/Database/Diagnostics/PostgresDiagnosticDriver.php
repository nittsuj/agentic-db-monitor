<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

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
        $columns = $connection->select(
            "SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS default_value
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );

        // Expression columns of an index (attnum 0) have no name, so they are skipped.
        $indexes = $connection->select(
            "SELECT i.relname AS index_name, a.attname AS column_name, k.seq,
                    ix.indisunique AS is_unique, ix.indisprimary AS is_primary
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class i ON i.oid = ix.indexrelid
             CROSS JOIN LATERAL unnest(ix.indkey::int2[]) WITH ORDINALITY AS k(attnum, seq)
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
             WHERE n.nspname = 'public' AND t.relname = ?
             ORDER BY i.relname, k.seq",
            [$table]
        );

        $foreignKeys = $connection->select(
            "SELECT c.conname AS constraint_name, a.attname AS column_name, k.seq,
                    rt.relname AS referenced_table, ra.attname AS referenced_column
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class rt ON rt.oid = c.confrelid
             CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(attnum, ref_attnum, seq)
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
             JOIN pg_attribute ra ON ra.attrelid = rt.oid AND ra.attnum = k.ref_attnum
             WHERE c.contype = 'f' AND n.nspname = 'public' AND t.relname = ?
             ORDER BY c.conname, k.seq",
            [$table]
        );

        return TableSchema::build($columns, $indexes, $foreignKeys);
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

    public function collectMetrics(ConnectionInterface $connection): array
    {
        $pool = $this->checkConnectionPool($connection);

        $uptime = $connection->selectOne(
            'SELECT EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time())) AS seconds'
        );
        $uptimeMinutes = max(1.0, ((float) ($uptime->seconds ?? 0)) / 60);

        try {
            $slow = $connection->selectOne(
                'SELECT COUNT(*) AS c FROM pg_stat_statements WHERE mean_exec_time > 1000'
            );
            $slowQueries = (int) ($slow->c ?? 0);
        } catch (QueryException) {
            // pg_stat_statements is optional; report no slow statements instead of
            // failing the whole collection tick.
            $slowQueries = 0;
        }

        return [
            'connection_pool_used' => $pool['max_connections'] > 0
                ? round(100 * $pool['active_connections'] / $pool['max_connections'], 2)
                : 0.0,
            'slow_queries_per_min' => round($slowQueries / $uptimeMinutes, 3),
        ];
    }
}
