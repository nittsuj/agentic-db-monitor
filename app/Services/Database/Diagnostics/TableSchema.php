<?php

declare(strict_types=1);

namespace App\Services\Database\Diagnostics;

/**
 * Shapes the raw column, index and foreign key rows of one table into the
 * get_table_schema tool output, and works out which foreign keys have no
 * supporting index so the model does not have to.
 */
final class TableSchema
{
    /**
     * @param  array<int, object|array<string, mixed>>  $columns  rows of name, type, nullable, default_value, ...
     * @param  array<int, object|array<string, mixed>>  $indexRows  one row per index column: index_name, column_name, seq, is_unique, is_primary
     * @param  array<int, object|array<string, mixed>>  $foreignKeyRows  one row per key column: constraint_name, column_name, seq, referenced_table, referenced_column
     * @return array{columns: array<int, array<string, mixed>>, indexes: list<array{name: string, columns: list<string>, unique: bool, primary: bool}>, foreign_keys: list<array{name: string, columns: list<string>, references: array{table: string, columns: list<string>}, indexed: bool}>, unindexed_foreign_keys: list<string>}
     */
    public static function build(array $columns, array $indexRows, array $foreignKeyRows): array
    {
        $indexes = self::indexes($indexRows);
        $foreignKeys = self::foreignKeys($foreignKeyRows, $indexes);

        return [
            'columns' => array_map(fn ($row): array => (array) $row, $columns),
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'unindexed_foreign_keys' => array_values(array_map(
                fn (array $key): string => implode(', ', $key['columns']),
                array_filter($foreignKeys, fn (array $key): bool => ! $key['indexed']),
            )),
        ];
    }

    /**
     * An index supports a foreign key when the key's columns are its leading
     * columns, in any order. That is what lets the database look up child rows
     * by the key, for joins and for ON DELETE checks on the parent.
     *
     * @param  list<string>  $keyColumns
     * @param  list<string>  $indexColumns
     */
    public static function supports(array $keyColumns, array $indexColumns): bool
    {
        $leading = array_slice($indexColumns, 0, count($keyColumns));

        sort($keyColumns);
        sort($leading);

        return $keyColumns !== [] && $leading === $keyColumns;
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    private static function indexes(array $rows): array
    {
        $indexes = [];

        foreach (self::sorted($rows) as $row) {
            $name = (string) $row['index_name'];
            $indexes[$name] ??= [
                'name' => $name,
                'columns' => [],
                'unique' => (bool) $row['is_unique'],
                'primary' => (bool) $row['is_primary'],
            ];
            $indexes[$name]['columns'][] = (string) $row['column_name'];
        }

        return array_values($indexes);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     * @param  list<array{name: string, columns: list<string>, unique: bool, primary: bool}>  $indexes
     * @return list<array{name: string, columns: list<string>, references: array{table: string, columns: list<string>}, indexed: bool}>
     */
    private static function foreignKeys(array $rows, array $indexes): array
    {
        $keys = [];

        foreach (self::sorted($rows) as $row) {
            $name = (string) $row['constraint_name'];
            $keys[$name] ??= [
                'name' => $name,
                'columns' => [],
                'references' => ['table' => (string) $row['referenced_table'], 'columns' => []],
                'indexed' => false,
            ];
            $keys[$name]['columns'][] = (string) $row['column_name'];
            $keys[$name]['references']['columns'][] = (string) $row['referenced_column'];
        }

        foreach ($keys as &$key) {
            foreach ($indexes as $index) {
                if (self::supports($key['columns'], $index['columns'])) {
                    $key['indexed'] = true;
                    break;
                }
            }
        }
        unset($key);

        return array_values($keys);
    }

    /**
     * Rows as arrays, ordered by their position within the index or key.
     *
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        $rows = array_map(fn ($row): array => (array) $row, $rows);

        usort($rows, fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);

        return $rows;
    }
}
