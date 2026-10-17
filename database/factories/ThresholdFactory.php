<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ThresholdOperator;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Threshold>
 */
class ThresholdFactory extends Factory
{
    protected $model = Threshold::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'key' => 'connection_pool_used',
            'operator' => ThresholdOperator::Gt,
            'value' => 80.0,
            'enabled' => true,
        ];
    }
}
