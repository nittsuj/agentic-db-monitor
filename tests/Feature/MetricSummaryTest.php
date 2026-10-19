<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Project;
use App\Services\Monitoring\MetricSummaryService;
use App\Services\Monitoring\MetricSummaryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarises_recent_samples_per_metric(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 4,
            'captured_at' => now()->subMinutes(4),
            'meta' => ['source' => 'live'],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 6,
            'captured_at' => now()->subMinutes(3),
            'meta' => ['source' => 'live'],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 10,
            'captured_at' => now()->subMinutes(2),
            'meta' => ['source' => 'live'],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 12,
            'captured_at' => now()->subMinute(),
            'meta' => ['source' => 'live'],
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'cpu_user_pct',
            'value' => 55.5,
            'captured_at' => now()->subMinute(),
            'meta' => ['source' => 'live'],
        ]);

        $summary = app(MetricSummaryService::class)->summarize($project);

        $this->assertSame(5, $summary['sample_count']);
        $this->assertCount(2, $summary['metrics']);

        $locks = $this->metric($summary, 'locks');

        $this->assertSame('Locks', $locks['label']);
        $this->assertSame('count', $locks['unit']);
        $this->assertSame(12.0, $locks['last']);
        $this->assertSame(4.0, $locks['min']);
        $this->assertSame(12.0, $locks['max']);
        $this->assertSame(8.0, $locks['mean']);
        $this->assertSame('up', $locks['trend']);
    }

    public function test_summarise_ignores_samples_outside_the_window(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 99,
            'captured_at' => now()->subHours(3),
        ]);

        $summary = app(MetricSummaryService::class)->summarize($project, 15);

        $this->assertSame(0, $summary['sample_count']);
        $this->assertSame([], $summary['metrics']);
    }

    public function test_summarise_reports_the_replay_source_and_anomaly_count(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 12,
            'captured_at' => now()->subMinute(),
            'meta' => ['source' => 'dbpa', 'scenario' => 'lock_waits', 'config' => '32_128', 'anomaly' => true],
        ]);

        $summary = app(MetricSummaryService::class)->summarize($project);

        $this->assertSame('dbpa', $summary['source']);
        $this->assertSame('lock_waits', $summary['scenario']);
        $this->assertSame('32_128', $summary['config']);
        $this->assertSame(1, $summary['anomalous_sample_count']);
    }

    public function test_summarise_of_an_unknown_project_is_empty(): void
    {
        $summary = app(MetricSummaryService::class)->summarizeById('00000000-0000-0000-0000-000000000000');

        $this->assertSame(0, $summary['sample_count']);
        $this->assertSame([], $summary['metrics']);
    }

    public function test_tool_returns_the_summary_as_json_text(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'locks',
            'value' => 7,
            'captured_at' => now()->subMinute(),
        ]);

        $tool = MetricSummaryTool::make($project->id);
        $payload = json_decode((string) $tool->handle(window_minutes: 30), true);

        $this->assertIsArray($payload);
        $this->assertSame($project->id, $payload['project_id']);
        $this->assertSame(30, $payload['window_minutes']);
        $this->assertSame('locks', $payload['metrics'][0]['key']);
        $this->assertSame(7.0, (float) $payload['metrics'][0]['last']);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function metric(array $summary, string $key): array
    {
        foreach ($summary['metrics'] as $metric) {
            if ($metric['key'] === $key) {
                return $metric;
            }
        }

        $this->fail("Metric {$key} is missing from the summary.");
    }
}
