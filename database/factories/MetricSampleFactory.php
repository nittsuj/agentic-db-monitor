<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MetricSample;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSample>
 */
class MetricSampleFactory extends Factory
{
    protected $model = MetricSample::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'key' => 'connection_pool_used',
            'value' => fake()->randomFloat(1, 10, 95),
            'meta' => null,
            'captured_at' => now(),
        ];
    }
}
