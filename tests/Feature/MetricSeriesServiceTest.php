<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Project;
use App\Services\Monitoring\MetricSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricSeriesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_the_raw_samples_for_a_short_range(): void
    {
        $project = Project::factory()->create();

        foreach (range(0, 4) as $index) {
            MetricSample::factory()->for($project)->create([
                'key' => 'locks',
                'value' => $index,
                'captured_at' => now()->subMinutes(10)->addSeconds($index * 5),
            ]);
        }

        $series = app(MetricSeriesService::class)->series($project, 15);

        $this->assertCount(1, $series);
        $this->assertSame([0.0, 1.0, 2.0, 3.0, 4.0], $series->get('locks')['values']->all());
        $this->assertSame(4.0, $series->get('locks')['latest']);
        $this->assertSame(5, $series->get('locks')['samples']);
    }

    public function test_aggregates_a_long_range_per_minute(): void
    {
        $project = Project::factory()->create();
        $minute = now()->startOfMinute()->subMinutes(30);

        foreach ([10.0, 20.0] as $index => $value) {
            MetricSample::factory()->for($project)->create([
                'key' => 'cpu_user_pct',
                'value' => $value,
                'captured_at' => $minute->copy()->addSeconds($index * 10),
            ]);
        }

        MetricSample::factory()->for($project)->create([
            'key' => 'cpu_user_pct',
            'value' => 40.0,
            'captured_at' => now()->subMinutes(20),
        ]);

        $series = app(MetricSeriesService::class)->series($project, 60);

        $this->assertSame([15.0, 40.0], $series->get('cpu_user_pct')['values']->all());
    }

    public function test_reports_the_peak_of_a_minute_for_lock_metrics(): void
    {
        $project = Project::factory()->create();
        $minute = now()->startOfMinute()->subMinutes(30);

        foreach ([1.0, 9.0] as $index => $value) {
            MetricSample::factory()->for($project)->create([
                'key' => 'locks',
                'value' => $value,
                'captured_at' => $minute->copy()->addSeconds($index * 10),
            ]);
        }

        $series = app(MetricSeriesService::class)->series($project, 60);

        $this->assertSame([9.0], $series->get('locks')['values']->all());
    }

    public function test_lists_the_strongest_anomaly_signals(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 1.0,
            'captured_at' => now()->subMinutes(5),
            'meta' => ['source' => 'dbpa', 'anomaly' => false],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 64.0,
            'captured_at' => now()->subMinutes(4),
            'meta' => ['source' => 'dbpa', 'anomaly' => true],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'cpu_user_pct',
            'value' => 20.0,
            'captured_at' => now()->subMinutes(5),
            'meta' => ['source' => 'dbpa', 'anomaly' => false],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'cpu_user_pct',
            'value' => 40.0,
            'captured_at' => now()->subMinutes(4),
            'meta' => ['source' => 'dbpa', 'anomaly' => true],
        ]);

        $signals = app(MetricSeriesService::class)->anomalySignals($project, 60);

        $this->assertCount(2, $signals);
        $this->assertSame('locks', $signals[0]['key']);
        $this->assertSame('Lock', $signals[0]['label']);
        $this->assertGreaterThan(0, $signals[0]['deviation']);
        $this->assertSame(1.0, $signals[0]['baseline']);
        $this->assertSame(64.0, $signals[0]['window']);
    }

    public function test_has_no_signals_without_an_anomaly_window(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 1.0,
            'captured_at' => now()->subMinutes(5),
        ]);

        $this->assertSame([], app(MetricSeriesService::class)->anomalySignals($project, 60));
    }
}
