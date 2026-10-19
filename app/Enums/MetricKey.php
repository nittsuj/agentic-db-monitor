<?php

declare(strict_types=1);

namespace App\Enums;

enum MetricKey: string
{
    case ConnectionPoolUsed = 'connection_pool_used';
    case ActiveConnections = 'active_connections';
    case Locks = 'locks';
    case LongRunningQueries = 'long_running_queries';
    case LockWaitingSessions = 'lock_waiting_sessions';
    case SlowQueriesPerMinute = 'slow_queries_per_min';
    case CpuUserPct = 'cpu_user_pct';
    case Load1m = 'load_1m';
    case Commits = 'commits';
    case RollbackRatio = 'rollback_ratio';
    case BufferHitRatio = 'buffer_hit_ratio';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $key): string => $key->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::ConnectionPoolUsed => 'Connection pool used',
            self::ActiveConnections => 'Active connections',
            self::Locks => 'Locks',
            self::LongRunningQueries => 'Long running queries',
            self::LockWaitingSessions => 'Lock waiting sessions',
            self::SlowQueriesPerMinute => 'Slow queries per minute',
            self::CpuUserPct => 'CPU user %',
            self::Load1m => 'Load (1m)',
            self::Commits => 'Commits',
            self::RollbackRatio => 'Rollback ratio %',
            self::BufferHitRatio => 'Buffer hit ratio %',
        };
    }

    /**
     * Metrics that only PostgreSQL exposes.
     */
    public function availableFor(DbDriver $driver): bool
    {
        return match ($this) {
            self::LongRunningQueries, self::LockWaitingSessions => $driver === DbDriver::PgSql,
            default => true,
        };
    }

    /**
     * Unit the value is expressed in.
     */
    public function unit(): string
    {
        return match ($this) {
            self::ConnectionPoolUsed, self::CpuUserPct, self::RollbackRatio, self::BufferHitRatio => '%',
            self::ActiveConnections, self::Locks, self::LongRunningQueries, self::LockWaitingSessions => 'count',
            self::Load1m => 'load',
            self::Commits, self::SlowQueriesPerMinute => 'per sample',
        };
    }

    /**
     * Name shown on the dashboard, following the dashboard specification.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::ConnectionPoolUsed => 'Koneksi %',
            self::ActiveConnections => 'Sesi aktif',
            self::Locks => 'Lock',
            self::LongRunningQueries => 'Query lama',
            self::LockWaitingSessions => 'Lock wait',
            self::SlowQueriesPerMinute => 'Slow query / menit',
            self::CpuUserPct => 'CPU user',
            self::Load1m => 'Load 1m',
            self::Commits => 'Commit / rollback',
            self::RollbackRatio => 'Rollback ratio',
            self::BufferHitRatio => 'Buffer hit',
        };
    }

    /**
     * Whether a rising value is a problem for this metric.
     */
    public function higherIsWorse(): bool
    {
        return $this !== self::BufferHitRatio;
    }
}
