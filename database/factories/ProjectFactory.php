<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DbDriver;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' DB',
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => fake()->slug(),
            'username' => fake()->userName(),
            'password' => 'secret',
            'options' => [],
            'is_active' => true,
        ];
    }
}
