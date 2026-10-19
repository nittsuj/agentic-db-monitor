<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActionCategory;
use App\Enums\ActionDecision;
use App\Enums\ActionStatus;
use App\Enums\ThresholdOperator;
use App\Livewire\Dashboard\HealthDashboard;
use App\Models\ActionLog;
use App\Models\MetricSample;
use App\Models\PendingAction;
use App\Models\Project;
use App\Models\Threshold;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_empty_state_when_no_project_is_active(): void
    {
        session()->forget('active_project_id');

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee('Belum ada project dipilih')
            ->assertSee('Tambahkan profil database atau pilih project dari sidebar.');
    }

    public function test_shows_latest_value_of_each_metric_for_active_project(): void
    {
        $project = Project::factory()->create(['name' => 'Production DB']);

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 50.0,
            'captured_at' => now()->subMinutes(10),
        ]);
        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 75.5,
            'captured_at' => now()->subMinutes(2),
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'slow_queries_per_min',
            'value' => 4.2,
            'captured_at' => now()->subMinutes(2),
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee('Production DB')
            ->assertSee('Koneksi %')
            ->assertSee('75.5')
            ->assertSee('Slow query / menit')
            ->assertSee('4.2');
    }

    public function test_does_not_show_data_from_another_project(): void
    {
        $projectA = Project::factory()->create(['name' => 'Project Alpha']);
        $projectB = Project::factory()->create(['name' => 'Project Beta']);

        MetricSample::factory()->for($projectB)->create([
            'key' => 'secret_metric_beta',
            'value' => 99.9,
            'captured_at' => now()->subMinutes(2),
        ]);

        PendingAction::factory()->for($projectB)->create([
            'command' => 'DROP TABLE project_b_data;',
            'status' => ActionStatus::Pending,
        ]);

        ActionLog::factory()->for($projectB)->create([
            'command' => 'TRUNCATE project_b_audit;',
            'decision' => ActionDecision::Executed,
        ]);

        session(['active_project_id' => $projectA->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee('Project Alpha')
            ->assertDontSee('Project Beta')
            ->assertDontSee('Secret Metric Beta')
            ->assertDontSee('DROP TABLE project_b_data;')
            ->assertDontSee('TRUNCATE project_b_audit;');
    }

    public function test_breach_callout_is_shown_for_the_latest_sample(): void
    {
        $project = Project::factory()->create();

        Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => ThresholdOperator::Gt,
            'value' => 80.0,
            'enabled' => true,
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 91.0,
            'captured_at' => now()->subMinutes(2),
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee('1 metrik melewati threshold')
            ->assertSee('Koneksi %')
            ->assertSee('Breach');
    }

    public function test_breach_callout_is_not_shown_for_disabled_threshold_or_older_samples(): void
    {
        $project = Project::factory()->create();

        // Disabled threshold
        Threshold::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'operator' => ThresholdOperator::Gt,
            'value' => 80.0,
            'enabled' => false,
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 91.0,
            'captured_at' => now()->subMinutes(2),
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertDontSee('melewati threshold')
            ->assertDontSee('Breach');

        // Now test older sample over threshold, but latest sample under threshold
        $threshold = Threshold::first();
        $threshold->update(['enabled' => true]);

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 60.0,
            'captured_at' => now()->subMinute(),
        ]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertDontSee('melewati threshold')
            ->assertSee('OK');
    }

    public function test_range_filter_excludes_samples_outside_window_and_clamps_invalid_ranges(): void
    {
        $project = Project::factory()->create();

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 55.0,
            'captured_at' => now()->subHours(5),
        ]);

        MetricSample::factory()->for($project)->create([
            'key' => 'connection_pool_used',
            'value' => 99.0,
            'captured_at' => now()->subHours(8),
        ]);

        session(['active_project_id' => $project->id]);

        // The six hour window excludes the eight hour sample.
        $component = Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->call('setRange', 360);

        $series = $component->get('series');
        $this->assertCount(1, $series);
        $this->assertEquals([55.0], $series->first()['values']->all());

        // An unknown range falls back to 24 hours.
        $component->call('setRange', 999);
        $this->assertSame(1440, $component->get('minutes'));
    }

    public function test_lists_only_pending_actions_under_pending_approvals(): void
    {
        $project = Project::factory()->create();

        PendingAction::factory()->for($project)->create([
            'command' => 'OPTIMIZE TABLE events;',
            'status' => ActionStatus::Pending,
            'category' => ActionCategory::Maintenance,
        ]);

        PendingAction::factory()->for($project)->create([
            'command' => 'ANALYZE TABLE events;',
            'status' => ActionStatus::Executed,
            'category' => ActionCategory::Maintenance,
        ]);

        session(['active_project_id' => $project->id]);

        $component = Livewire::withoutLazyLoading()->test(HealthDashboard::class);

        $component->assertSee('OPTIMIZE TABLE events;');

        $pending = $component->get('pendingActions');
        $this->assertCount(1, $pending);
        $this->assertSame('OPTIMIZE TABLE events;', $pending->first()->command);
    }

    public function test_renders_recent_action_logs_with_decision_badge(): void
    {
        $project = Project::factory()->create();

        ActionLog::factory()->for($project)->create([
            'command' => 'VACUUM FULL analytics;',
            'decision' => ActionDecision::Approved,
        ]);

        ActionLog::factory()->for($project)->create([
            'command' => 'DROP TABLE legacy_data;',
            'decision' => ActionDecision::Denied,
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee('VACUUM FULL analytics;')
            ->assertSee('approved')
            ->assertSee('DROP TABLE legacy_data;')
            ->assertSee('denied');
    }

    public function test_dashboard_renders_against_demo_seeder_data(): void
    {
        $this->seed(DemoSeeder::class);

        $project = Project::where('driver', 'mysql')->firstOrFail();
        session(['active_project_id' => $project->id]);

        Livewire::withoutLazyLoading()
            ->test(HealthDashboard::class)
            ->assertSee($project->name)
            ->assertSee('mysql')
            ->assertSee('Koneksi %')
            ->assertSee('Slow query / menit')
            ->assertSee('Persetujuan tertunda')
            ->assertSee('Aksi terakhir')
            ->assertSee('Threshold');
    }
}
