<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelemetryClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_samples_outside_the_retention_window(): void
    {
        $project = Project::factory()->create();
        $old = MetricSample::factory()->for($project)->create(['captured_at' => now()->subHours(50)]);
        $fresh = MetricSample::factory()->for($project)->create(['captured_at' => now()->subHour()]);
        $threshold = Threshold::factory()->for($project)->create();

        $this->artisan('telemetry:clear', ['--hours' => 48])
            ->expectsOutputToContain('Deleted 1 metric sample(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('metric_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('metric_samples', ['id' => $fresh->id]);
        $this->assertDatabaseHas('thresholds', ['id' => $threshold->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_rejects_a_retention_window_below_one_hour(): void
    {
        $this->artisan('telemetry:clear', ['--hours' => 0])
            ->expectsOutputToContain('at least 1')
            ->assertFailed();
    }
}
