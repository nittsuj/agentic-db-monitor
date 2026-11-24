<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\Diagnostics\TableSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableSchemaTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, list<string>, bool}>
     */
    public static function supportCases(): array
    {
        return [
            'single column index' => [['sku_id'], ['sku_id'], true],
            'key is the leading column' => [['sku_id'], ['sku_id', 'created_at'], true],
            'key is not the leading column' => [['sku_id'], ['created_at', 'sku_id'], false],
            'composite key, same order' => [['a', 'b'], ['a', 'b', 'c'], true],
            'composite key, other order' => [['a', 'b'], ['b', 'a'], true],
            'index shorter than the key' => [['a', 'b'], ['a'], false],
            'unrelated index' => [['sku_id'], ['dispatch_id'], false],
        ];
    }

    /**
     * @param  list<string>  $key
     * @param  list<string>  $index
     */
    #[DataProvider('supportCases')]
    public function test_an_index_supports_a_key_only_through_its_leading_columns(array $key, array $index, bool $expected): void
    {
        $this->assertSame($expected, TableSchema::supports($key, $index));
    }

    public function test_it_groups_rows_into_indexes_and_flags_unindexed_foreign_keys(): void
    {
        $schema = TableSchema::build(
            columns: [(object) ['name' => 'id', 'type' => 'uuid']],
            indexRows: [
                // Out of order on purpose: seq decides the column order.
                (object) ['index_name' => 'idx_po_status_date', 'column_name' => 'ordered_at', 'seq' => 2, 'is_unique' => false, 'is_primary' => false],
                (object) ['index_name' => 'po_pkey', 'column_name' => 'id', 'seq' => 1, 'is_unique' => true, 'is_primary' => true],
                (object) ['index_name' => 'idx_po_status_date', 'column_name' => 'status', 'seq' => 1, 'is_unique' => false, 'is_primary' => false],
                (object) ['index_name' => 'idx_po_supplier', 'column_name' => 'supplier_id', 'seq' => 1, 'is_unique' => 0, 'is_primary' => 0],
            ],
            foreignKeyRows: [
                ['constraint_name' => 'po_supplier_fkey', 'column_name' => 'supplier_id', 'seq' => 1, 'referenced_table' => 'suppliers', 'referenced_column' => 'id'],
                ['constraint_name' => 'po_approved_by_fkey', 'column_name' => 'approved_by_id', 'seq' => 1, 'referenced_table' => 'users', 'referenced_column' => 'id'],
            ],
        );

        $this->assertSame([['name' => 'id', 'type' => 'uuid']], $schema['columns']);
        $this->assertSame([
            ['name' => 'po_pkey', 'columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'idx_po_status_date', 'columns' => ['status', 'ordered_at'], 'unique' => false, 'primary' => false],
            ['name' => 'idx_po_supplier', 'columns' => ['supplier_id'], 'unique' => false, 'primary' => false],
        ], $schema['indexes']);
        $this->assertSame([
            ['name' => 'po_supplier_fkey', 'columns' => ['supplier_id'], 'references' => ['table' => 'suppliers', 'columns' => ['id']], 'indexed' => true],
            ['name' => 'po_approved_by_fkey', 'columns' => ['approved_by_id'], 'references' => ['table' => 'users', 'columns' => ['id']], 'indexed' => false],
        ], $schema['foreign_keys']);
        $this->assertSame(['approved_by_id'], $schema['unindexed_foreign_keys']);
    }

    public function test_composite_foreign_keys_keep_their_column_pairs(): void
    {
        $schema = TableSchema::build(
            columns: [],
            indexRows: [],
            foreignKeyRows: [
                ['constraint_name' => 'line_fkey', 'column_name' => 'line_no', 'seq' => 2, 'referenced_table' => 'order_lines', 'referenced_column' => 'no'],
                ['constraint_name' => 'line_fkey', 'column_name' => 'order_id', 'seq' => 1, 'referenced_table' => 'order_lines', 'referenced_column' => 'order_id'],
            ],
        );

        $this->assertSame(['order_id', 'line_no'], $schema['foreign_keys'][0]['columns']);
        $this->assertSame(['order_id', 'no'], $schema['foreign_keys'][0]['references']['columns']);
        $this->assertSame(['order_id, line_no'], $schema['unindexed_foreign_keys']);
    }

    public function test_a_table_without_indexes_or_keys_has_empty_lists(): void
    {
        $this->assertSame(
            ['columns' => [], 'indexes' => [], 'foreign_keys' => [], 'unindexed_foreign_keys' => []],
            TableSchema::build([], [], []),
        );
    }
}
