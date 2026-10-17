<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Enums\ActionCategory;

class StatementClassifier
{
    private const DDL_PATTERNS = [
        '/^\s*(create|alter|drop|truncate|rename)\s+/i',
    ];

    private const MAINTENANCE_PATTERNS = [
        '/^\s*(vacuum|analyze|optimize|repair|reindex)\s+/i',
    ];

    private const MUTATING_PATTERNS = [
        '/^\s*(insert|update|delete|replace|merge|grant|revoke)\s+/i',
    ];

    public function classify(string $sql): ?ActionCategory
    {
        $trimmed = trim($sql);

        foreach (self::DDL_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return ActionCategory::Ddl;
            }
        }

        foreach (self::MAINTENANCE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return ActionCategory::Maintenance;
            }
        }

        foreach (self::MUTATING_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return ActionCategory::Dml;
            }
        }

        return null;
    }

    public function isMutating(string $sql): bool
    {
        return $this->classify($sql) !== null;
    }
}
