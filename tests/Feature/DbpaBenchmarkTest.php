<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BenchmarkReport;
use App\Models\Project;
use App\Services\Benchmark\DiagnosisBenchmark;
use App\Services\Benchmark\LlmScenarioPredictor;
use App\Services\Benchmark\ScenarioPredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class DbpaBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private string $csvRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvRoot = sys_get_temp_dir().'/dbpa-benchmark-'.Str::random(8);
        File::ensureDirectoryExists($this->csvRoot.'/pgsql/32_128');

        File::put($this->csvRoot.'/pgsql/32_128/normal.csv', implode("\n", [
            'case,row,anomaly,connection_pool_used,locks,cpu_user_pct,buffer_hit_ratio',
            '0,0,0,10,1,20,99',
            '0,1,0,11,2,21,99',
            '1,0,0,10,1,19,98',
            '1,1,0,12,2,22,99',
        ]));

        File::put($this->csvRoot.'/pgsql/32_128/lock_waits.csv', implode("\n", [
            'case,row,anomaly,connection_pool_used,locks,cpu_user_pct,buffer_hit_ratio',
            '0,0,1,10,60,25,99',
            '0,1,1,11,70,26,99',
            '1,0,1,10,55,24,98',
            '1,1,1,11,65,27,98',
        ]));

        File::put($this->csvRoot.'/pgsql/32_128/heavy_workload.csv', implode("\n", [
            'case,row,anomaly,connection_pool_used,locks,cpu_user_pct,buffer_hit_ratio',
            '0,0,1,40,20,90,80',
            '0,1,1,45,22,95,78',
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->csvRoot);

        parent::tearDown();
    }

    public function test_benchmark_scores_the_agent_against_the_scenario_label(): void
    {
        $results = app(DiagnosisBenchmark::class)->run(
            'pgsql',
            '32_128',
            $this->csvRoot,
            ['lock_waits', 'heavy_workload'],
            2,
            new class implements ScenarioPredictor
            {
                public function predict(array $summary, array $labels): string
                {
                    return $summary['scenario'] === 'lock_waits' ? 'lock_waits' : 'lock_waits';
                }
            },
        );

        $this->assertCount(2, $results);
        $this->assertSame('lock_waits', $results[0]['scenario']);
        $this->assertSame(2, $results[0]['cases']);
        $this->assertSame(2, $results[0]['correct']);
        $this->assertSame(1.0, $results[0]['accuracy']);

        $this->assertSame('heavy_workload', $results[1]['scenario']);
        $this->assertSame(0, $results[1]['correct']);
        $this->assertSame(0.0, $results[1]['accuracy']);
    }

    public function test_benchmark_matches_a_prediction_that_is_wrapped_in_a_sentence(): void
    {
        $results = app(DiagnosisBenchmark::class)->run(
            'pgsql',
            '32_128',
            $this->csvRoot,
            ['lock_waits'],
            1,
            new class implements ScenarioPredictor
            {
                public function predict(array $summary, array $labels): string
                {
                    return 'The root cause is lock_waits.';
                }
            },
        );

        $this->assertSame(1, $results[0]['correct']);
    }

    public function test_benchmark_command_stores_an_accuracy_report_per_scenario(): void
    {
        $project = Project::factory()->create();

        $this->fakePredictor('lock_waits');

        $this->artisan('dbpa:benchmark', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot,
            '--cases' => 1,
            '--project' => $project->id,
        ])->assertSuccessful();

        $this->assertSame(2, BenchmarkReport::count());

        $report = BenchmarkReport::where('scenario', 'lock_waits')->firstOrFail();

        $this->assertSame($project->id, $report->project_id);
        $this->assertSame('pgsql', $report->engine);
        $this->assertSame('32_128', $report->config);
        $this->assertSame(1, $report->cases);
        $this->assertSame(1, $report->correct);
        $this->assertSame(1.0, $report->accuracy);
        $this->assertSame('lock_waits', $report->details[0]['predicted']);
        $this->assertTrue($report->details[0]['correct']);
    }

    public function test_benchmark_records_a_failed_prediction_without_aborting_the_run(): void
    {
        $results = app(DiagnosisBenchmark::class)->run(
            'pgsql',
            '32_128',
            $this->csvRoot,
            ['lock_waits'],
            1,
            new class implements ScenarioPredictor
            {
                public function predict(array $summary, array $labels): string
                {
                    throw new \RuntimeException('provider unavailable');
                }
            },
        );

        $this->assertSame(1, $results[0]['cases']);
        $this->assertSame(0, $results[0]['correct']);
        $this->assertSame('provider unavailable', $results[0]['details'][0]['error']);
    }

    public function test_benchmark_command_fails_when_the_scenario_files_are_missing(): void
    {
        $this->artisan('dbpa:benchmark', [
            '--engine' => 'pgsql',
            '--config' => '32_128',
            '--path' => $this->csvRoot.'/missing',
        ])->assertFailed();
    }

    public function test_llm_predictor_asks_the_provider_for_one_label(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()->withText('lock_waits'),
        ]);

        $prediction = app(LlmScenarioPredictor::class)->predict([
            'signals' => [
                ['key' => 'locks', 'baseline' => 1.0, 'window_mean' => 60.0, 'deviation' => 5.9],
            ],
        ], ['lock_waits', 'heavy_workload']);

        $this->assertSame('lock_waits', $prediction);

        $fake->assertRequest(function ($recorded): void {
            $prompt = $recorded[0]->prompt();

            $this->assertStringContainsString('locks: +5.90', (string) $prompt);
            $this->assertStringContainsString('lock_waits, heavy_workload', (string) $prompt);
        });
    }

    private function fakePredictor(string $answer): void
    {
        $this->app->bind(LlmScenarioPredictor::class, fn (): ScenarioPredictor => new class($answer) implements ScenarioPredictor
        {
            public function __construct(private readonly string $answer) {}

            public function predict(array $summary, array $labels): string
            {
                return $this->answer;
            }
        });
    }
}
