<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QueryDiagnostician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Extra rows for tests. The app itself uses the row its migration inserts.
 *
 * @extends Factory<QueryDiagnostician>
 */
class QueryDiagnosticianFactory extends Factory
{
    protected $model = QueryDiagnostician::class;

    public function definition(): array
    {
        return [
            'slug' => QueryDiagnostician::SLUG.'-'.fake()->unique()->lexify('??????'),
            'name' => 'QueryDiagnostician',
            'description' => 'Runs the read-only diagnostic checks on the target database.',
            'handles' => ['list_tables', 'get_table_schema', 'get_slow_queries', 'check_connection_pool', 'analyze_table_bloat'],
        ];
    }
}
