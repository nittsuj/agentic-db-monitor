<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\Project;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanRun>
 */
class ScanRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'status' => ScanStatus::Queued,
            'total' => 0,
            'processed' => 0,
        ];
    }
}
