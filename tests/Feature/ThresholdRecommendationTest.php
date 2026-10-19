<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Threshold;
use App\Services\Benchmark\ThresholdRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThresholdRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private string $csvRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvRoot = sys_get_temp_dir().'/dbpa-threshold-'.Str::random(8);
        File::ensureDirectoryExists($this->csvRoot.'/pgsql/32_128');

        $normal = ['case,row,anomaly,connection_pool_used,locks,cpu_user_pct,buffer_hit_ratio'];

        for ($case = 0; $case < 4; $case++) {
            for ($row = 0; $row < 3; $row++) {
                $normal[] = sprintf('%d,%d,0,%d,%d,%d,%d', $case, $row, 10 + $row, 1 + $row, 20 + $row, 99);
            }
        }

        File::put($this->csvRoot.'/pgsql/32_128/normal.csv', implode("\n", $normal));

        $anomaly = ['case,row,anomaly,connection_pool_used,locks,cpu_user_pct,buffer_hit_ratio'];

        for ($case = 0; $case < 4; $case++) {
            for ($row = 0; $row < 3; $row++) {
                $anomaly[] = sprintf('%d,%d,1,%d,%d,%d,%d', $case, $row, 40 + $row, 60 + $row, 80 + $row, 60);
            }
        }

        File::put($this->csvRoot.'/pgsql/32_128/lock_waits.csv', implode("\n", $anomaly));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->csvRoot);

        parent::tearDown();
    }

    public function test_recommends_a_threshold_that_separates_normal_from_anomalous_samples(): void
    {
        $recommendations = app(ThresholdRecommendationService::class)
            ->recommend('pgsql', '32_128', $this->csvRoot);

        $locks = $this->recommendation($recommendations, 'locks');

        $this->assertSame('gt', $locks['operator']);
        $this->assertSame(3.0, $locks['value']);
        $this->assertSame(1.0, $locks['coverage']);
        $this->assertTrue($locks['separable']);

        $pool = $this->recommendation($recommendations, 'connection_pool_used');

        $this->assertSame('gt', $pool['operator']);
        $this->assertSame(12.0, $pool['value']);
        $this->assertTrue($pool['separable']);
    }

    public function test_recommends_a_lower_bound_for_metrics_where_a_drop_is_the_problem(): void
    {
        $recommendations = app(ThresholdRecommendationService::class)
            ->recommend('pgsql', '32_128', $this->csvRoot);

        $hitRatio = $this->recommendation($recommendations, 'buffer_hit_ratio');

        $this->assertSame('lt', $hitRatio['operator']);
        $this->assertSame(99.0, $hitRatio['value']);
        $this->assertTrue($hitRatio['separable']);
    }

    public function test_command_lists_the_recommendations_without_writing_them(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:recommend-thresholds', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot,
            '--project' => $project->id,
        ])->assertSuccessful();

        $this->assertSame(0, Threshold::count());
    }

    public function test_command_applies_the_recommendations_to_a_project(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:recommend-thresholds', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot,
            '--project' => $project->id,
            '--apply' => true,
        ])->assertSuccessful();

        $threshold = Threshold::where('project_id', $project->id)
            ->where('key', 'locks')
            ->firstOrFail();

        $this->assertSame('gt', $threshold->operator->value);
        $this->assertSame(3.0, $threshold->value);
        $this->assertTrue($threshold->enabled);
    }

    public function test_command_replaces_the_current_rule_of_a_recommended_metric(): void
    {
        $project = Project::factory()->create();

        Threshold::factory()->for($project)->create([
            'key' => 'locks',
            'operator' => 'gt',
            'value' => 42,
        ]);

        $this->artisan('dbpa:recommend-thresholds', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot,
            '--project' => $project->id,
            '--apply' => true,
        ])->assertSuccessful();

        $threshold = Threshold::where('project_id', $project->id)->where('key', 'locks')->firstOrFail();

        $this->assertSame(1, Threshold::where('project_id', $project->id)->where('key', 'locks')->count());
        $this->assertSame(3.0, $threshold->value);
        $this->assertSame('gt', $threshold->operator->value);
    }

    public function test_command_fails_when_the_dataset_is_missing(): void
    {
        $this->artisan('dbpa:recommend-thresholds', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot.'/missing',
        ])->assertFailed();
    }

    /**
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    private function recommendation(array $recommendations, string $key): array
    {
        foreach ($recommendations as $recommendation) {
            if ($recommendation['key'] === $key) {
                return $recommendation;
            }
        }

        $this->fail("No recommendation for {$key}.");
    }
}
