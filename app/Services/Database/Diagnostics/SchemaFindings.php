<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

/**
 * Problems a schema scan reports for one table, read from the output of
 * DiagnosticDriver::getTableSchema(). Only facts the schema proves are
 * reported; nothing here guesses at how the table is used.
 */
final class SchemaFindings
{
    /**
     * @param  array{indexes?: list<array{primary?: bool}>, unindexed_foreign_keys?: list<string>}  $schema
     * @return list<string>
     */
    public static function for(array $schema): array
    {
        $issues = [];

        $hasPrimaryKey = collect($schema['indexes'] ?? [])->contains(fn (array $index): bool => (bool) ($index['primary'] ?? false));

        if (! $hasPrimaryKey) {
            $issues[] = 'Tidak punya primary key';
        }

        foreach ($schema['unindexed_foreign_keys'] ?? [] as $columns) {
            $issues[] = "Foreign key tanpa index: {$columns}";
        }

        return $issues;
    }
}
