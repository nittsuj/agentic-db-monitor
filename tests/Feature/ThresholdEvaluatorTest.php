<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Threshold;
use App\Models\User;
use App\Notifications\ThresholdBreached;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ThresholdEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_breaches_and_notifies_the_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create();
        $threshold = Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => 'gt',
            'value' => 80,
            'enabled' => true,
        ]);

        $breaches = (new ThresholdEvaluator)->evaluate($project, [
            'connection_pool_used' => 92.5,
            'slow_queries_per_min' => 1.0,
        ]);

        $this->assertCount(1, $breaches);
        $this->assertSame($threshold->id, $breaches[0]['threshold']->id);
        $this->assertSame(92.5, $breaches[0]['value']);

        Notification::assertSentTo($user, ThresholdBreached::class);
    }

    public function test_suppresses_repeated_alerts_during_the_cooldown(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $project = Project::factory()->create();
        Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => 'gt',
            'value' => 80,
            'enabled' => true,
        ]);

        $evaluator = new ThresholdEvaluator;
        $samples = ['connection_pool_used' => 92.5];

        $evaluator->evaluate($project, $samples);
        $evaluator->evaluate($project, $samples);

        Notification::assertSentToTimes($user, ThresholdBreached::class, 1);
    }

    public function test_supports_the_lt_operator(): void
    {
        Notification::fake();
        User::factory()->create();

        $project = Project::factory()->create();
        Threshold::factory()->for($project)->create([
            'key' => 'buffer_hit_ratio',
            'operator' => 'lt',
            'value' => 90,
            'enabled' => true,
        ]);

        $breaches = (new ThresholdEvaluator)->evaluate($project, ['buffer_hit_ratio' => 72.0]);

        $this->assertCount(1, $breaches);
    }

    public function test_ignores_disabled_thresholds_and_unknown_metrics(): void
    {
        Notification::fake();
        User::factory()->create();

        $project = Project::factory()->create();
        Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => 'gt',
            'value' => 80,
            'enabled' => false,
        ]);
        Threshold::factory()->for($project)->create([
            'key' => 'commits',
            'operator' => 'lt',
            'value' => 10,
            'enabled' => true,
        ]);

        $breaches = (new ThresholdEvaluator)->evaluate($project, ['connection_pool_used' => 99.0]);

        $this->assertSame([], $breaches);
        Notification::assertNothingSent();
    }
}
