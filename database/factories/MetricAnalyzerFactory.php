<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MetricAnalyzer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Extra rows for tests. The app itself uses the row its migration inserts.
 *
 * @extends Factory<MetricAnalyzer>
 */
class MetricAnalyzerFactory extends Factory
{
    protected $model = MetricAnalyzer::class;

    public function definition(): array
    {
        return [
            'slug' => MetricAnalyzer::SLUG.'-'.fake()->unique()->lexify('??????'),
            'name' => 'MetricAnalyzer',
            'description' => 'Summarises the collected metrics of the project.',
            'handles' => ['get_metric_summary'],
        ];
    }
}
