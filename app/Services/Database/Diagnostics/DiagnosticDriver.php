<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

use Illuminate\Database\ConnectionInterface;

interface DiagnosticDriver
{
    /** @return array<int, string> */
    public function listTables(ConnectionInterface $connection): array;

    /** @return array<int, array<string, mixed>> */
    public function getTableSchema(ConnectionInterface $connection, string $table): array;

    /** @return array<int, array<string, mixed>> */
    public function getSlowQueries(ConnectionInterface $connection, int $limit = 10): array;

    /** @return array<string, mixed> */
    public function checkConnectionPool(ConnectionInterface $connection): array;

    /** @return array<int, array<string, mixed>> */
    public function analyzeTableBloat(ConnectionInterface $connection): array;
}
