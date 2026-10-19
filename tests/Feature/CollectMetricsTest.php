<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectMetrics;
use App\Models\Project;
use App\Models\Threshold;
use App\Models\User;
use App\Notifications\ThresholdBreached;
use App\Services\Monitoring\MetricCollector;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CollectMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_samples_for_active_projects_only(): void
    {
        $active = Project::factory()->create(['is_active' => true]);
        Project::factory()->create(['is_active' => false]);

        $collector = Mockery::mock(MetricCollector::class);
        $collector->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(fn (Project $project): bool => $project->is($active)))
            ->andReturn([
                'connection_pool_used' => 71.5,
                'slow_queries_per_min' => 3.0,
            ]);

        (new CollectMetrics)->handle($collector, new ThresholdEvaluator);

        $this->assertDatabaseHas('metric_samples', [
            'project_id' => $active->id,
            'key' => 'connection_pool_used',
            'value' => 71.5,
        ]);
        $this->assertDatabaseHas('metric_samples', [
            'project_id' => $active->id,
            'key' => 'slow_queries_per_min',
            'value' => 3.0,
        ]);
        $this->assertSame(2, \DB::table('metric_samples')->count());
    }

    public function test_notifies_once_per_threshold_cooldown(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create(['is_active' => true]);
        Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => 'gt',
            'value' => 80,
            'enabled' => true,
        ]);

        $collector = Mockery::mock(MetricCollector::class);
        $collector->shouldReceive('collect')->twice()->andReturn([
            'connection_pool_used' => 95.0,
            'slow_queries_per_min' => 1.0,
        ]);

        (new CollectMetrics)->handle($collector, new ThresholdEvaluator);
        (new CollectMetrics)->handle($collector, new ThresholdEvaluator);

        Notification::assertSentToTimes($user, ThresholdBreached::class, 1);
    }

    public function test_a_failing_project_does_not_stop_the_others(): void
    {
        $broken = Project::factory()->create(['is_active' => true]);
        $healthy = Project::factory()->create(['is_active' => true]);

        $collector = Mockery::mock(MetricCollector::class);
        $collector->shouldReceive('collect')->twice()->andReturnUsing(function (Project $project) use ($broken): array {
            if ($project->is($broken)) {
                throw new RuntimeException('connection refused');
            }

            return ['connection_pool_used' => 42.0, 'slow_queries_per_min' => 0.0];
        });

        (new CollectMetrics)->handle($collector, new ThresholdEvaluator);

        $this->assertDatabaseHas('metric_samples', ['project_id' => $healthy->id]);
        $this->assertDatabaseMissing('metric_samples', ['project_id' => $broken->id]);
    }
}
