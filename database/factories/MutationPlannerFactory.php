<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MutationPlanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Extra rows for tests. The app itself uses the row its migration inserts.
 *
 * @extends Factory<MutationPlanner>
 */
class MutationPlannerFactory extends Factory
{
    protected $model = MutationPlanner::class;

    public function definition(): array
    {
        return [
            'slug' => MutationPlanner::SLUG.'-'.fake()->unique()->lexify('??????'),
            'name' => 'MutationPlanner',
            'description' => 'Queues a requested database change for approval.',
            'handles' => ['propose_mutation'],
        ];
    }
}
