<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\Diagnostics\SchemaFindings;
use PHPUnit\Framework\TestCase;

class SchemaFindingsTest extends TestCase
{
    public function test_a_table_with_a_primary_key_and_indexed_keys_is_fine(): void
    {
        $this->assertSame([], SchemaFindings::for([
            'indexes' => [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true]],
            'unindexed_foreign_keys' => [],
        ]));
    }

    public function test_reports_a_missing_primary_key(): void
    {
        $this->assertSame(['Tidak punya primary key'], SchemaFindings::for([
            'indexes' => [['name' => 'idx_email', 'columns' => ['email'], 'unique' => true, 'primary' => false]],
            'unindexed_foreign_keys' => [],
        ]));
    }

    public function test_reports_each_foreign_key_without_an_index(): void
    {
        $this->assertSame(
            ['Foreign key tanpa index: user_id', 'Foreign key tanpa index: tenant_id, region_id'],
            SchemaFindings::for([
                'indexes' => [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'primary' => true]],
                'unindexed_foreign_keys' => ['user_id', 'tenant_id, region_id'],
            ]),
        );
    }
}
