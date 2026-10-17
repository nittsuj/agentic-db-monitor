<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricSamplePrunableTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_old_keeps_fresh(): void
    {
        $project = Project::factory()->create();

        $old = MetricSample::factory()->for($project)->create([
            'captured_at' => now()->subHours(49),
        ]);
        $fresh = MetricSample::factory()->for($project)->create([
            'captured_at' => now()->subHour(),
        ]);

        $projectCountBefore = Project::count();
        $thresholdCountBefore = Threshold::count();

        (new MetricSample)->pruneAll();

        $this->assertDatabaseMissing('metric_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('metric_samples', ['id' => $fresh->id]);
        $this->assertSame($projectCountBefore, Project::count());
        $this->assertSame($thresholdCountBefore, Threshold::count());
    }
}
