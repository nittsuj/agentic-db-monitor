<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class DbpaImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csvRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvRoot = sys_get_temp_dir().'/dbpa-test-'.Str::random(8);
        File::ensureDirectoryExists($this->csvRoot.'/pgsql/32_128');

        File::put($this->csvRoot.'/pgsql/32_128/normal.csv', implode("\n", [
            'case,row,anomaly,connection_pool_used,slow_queries_per_min',
            '0,0,0,50,1',
            '0,1,0,52,2',
            '1,0,0,51,1',
            '1,1,0,53,2',
        ]));

        File::put($this->csvRoot.'/pgsql/32_128/lock_waits.csv', implode("\n", [
            'case,row,anomaly,connection_pool_used,slow_queries_per_min',
            '0,0,1,90,5',
            '0,1,1,95,6',
            '1,0,1,91,5',
            '1,1,1,96,6',
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->csvRoot);

        parent::tearDown();
    }

    public function test_imports_normal_cases_with_replay_timestamps(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:import', [
            '--project' => $project->id,
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--scenario' => 'normal',
            '--path' => $this->csvRoot,
            '--cases' => 1,
        ])->assertSuccessful();

        $samples = MetricSample::where('project_id', $project->id)->get();

        $this->assertCount(4, $samples);
        $this->assertTrue($samples->every(fn (MetricSample $sample): bool => $sample->meta['source'] === 'dbpa'));
        $this->assertTrue($samples->every(fn (MetricSample $sample): bool => $sample->meta['scenario'] === 'normal'));
        $this->assertTrue($samples->every(fn (MetricSample $sample): bool => $sample->captured_at->lte(now())));
    }

    public function test_prepends_a_normal_case_before_an_anomalous_case(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:import', [
            '--project' => $project->id,
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--scenario' => 'lock_waits',
            '--path' => $this->csvRoot,
            '--cases' => 1,
        ])->assertSuccessful();

        $samples = MetricSample::where('project_id', $project->id)->get();

        $this->assertCount(8, $samples);
        $this->assertSame(4, $samples->filter(fn (MetricSample $sample): bool => $sample->meta['anomaly'] === true)->count());
        $this->assertSame(4, $samples->filter(fn (MetricSample $sample): bool => $sample->meta['anomaly'] === false)->count());
    }

    public function test_no_normal_flag_imports_only_the_selected_case(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:import', [
            '--project' => $project->id,
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--scenario' => 'lock_waits',
            '--path' => $this->csvRoot,
            '--cases' => 1,
            '--no-normal' => true,
        ])->assertSuccessful();

        $this->assertSame(4, MetricSample::where('project_id', $project->id)->count());
    }

    public function test_reimporting_replaces_previous_dbpa_samples_for_the_project(): void
    {
        $project = Project::factory()->create();

        $options = [
            '--project' => $project->id,
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot,
            '--cases' => 1,
        ];

        $this->artisan('dbpa:import', $options + ['--scenario' => 'normal'])->assertSuccessful();
        $this->artisan('dbpa:import', $options + ['--scenario' => 'lock_waits'])->assertSuccessful();

        $this->assertSame(8, MetricSample::where('project_id', $project->id)->count());
        $this->assertSame(0, MetricSample::where('project_id', $project->id)->where('meta->scenario', 'normal')->count());
    }

    public function test_replay_replaces_the_previous_telemetry_of_the_project(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 42,
            'captured_at' => now()->subHour(),
            'meta' => ['source' => 'demo-seeder'],
        ]);

        $this->artisan('dbpa:import', [
            '--project' => $project->id,
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--scenario' => 'lock_waits',
            '--path' => $this->csvRoot,
            '--cases' => 1,
        ])->assertSuccessful();

        $samples = MetricSample::where('project_id', $project->id)->get();

        $this->assertSame(8, $samples->count());
        $this->assertTrue($samples->every(fn (MetricSample $sample): bool => $sample->meta['source'] === 'dbpa'));
    }

    public function test_rejects_a_missing_scenario_file(): void
    {
        $project = Project::factory()->create();

        $this->artisan('dbpa:import', [
            '--project' => $project->id,
            '--scenario' => 'missing',
            '--path' => $this->csvRoot,
        ])->assertFailed();
    }

    public function test_rejects_an_unknown_engine(): void
    {
        $this->artisan('dbpa:import', [
            '--engine' => 'sqlite',
            '--path' => $this->csvRoot,
        ])->assertFailed();
    }
}
