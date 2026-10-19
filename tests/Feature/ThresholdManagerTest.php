<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Projects\ThresholdManager;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThresholdManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_active_project_thresholds(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        Threshold::factory()->for($project)->create(['key' => 'connection_pool_used']);
        Threshold::factory()->for($other)->create(['key' => 'slow_queries_per_min']);

        session(['active_project_id' => $project->id]);

        $otherThreshold = Threshold::factory()->for($other)->create(['key' => 'slow_queries_per_min']);

        Livewire::test(ThresholdManager::class)
            ->assertSee('connection_pool_used')
            ->assertDontSee($otherThreshold->id);
    }

    public function test_create_threshold(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->set('key', 'slow_queries_per_min')
            ->set('operator', 'gt')
            ->set('value', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('thresholds', [
            'project_id' => $project->id,
            'key' => 'slow_queries_per_min',
        ]);
    }

    public function test_accepts_metrics_from_the_extended_key_set(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->set('key', 'buffer_hit_ratio')
            ->set('operator', 'lt')
            ->set('value', 90)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('thresholds', [
            'project_id' => $project->id,
            'key' => 'buffer_hit_ratio',
        ]);
    }

    public function test_rejects_unknown_metric_keys(): void
    {
        $project = Project::factory()->create();
        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->set('key', 'made_up_metric')
            ->call('save')
            ->assertHasErrors(['key']);
    }

    public function test_update_threshold(): void
    {
        $project = Project::factory()->create();
        $threshold = Threshold::factory()->for($project)->create(['value' => 80]);

        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->call('edit', $threshold->id)
            ->set('value', 90)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(90.0, (float) $threshold->fresh()->value);
    }

    public function test_delete_threshold(): void
    {
        $project = Project::factory()->create();
        $threshold = Threshold::factory()->for($project)->create();

        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->call('delete', $threshold->id);

        $this->assertDatabaseMissing('thresholds', ['id' => $threshold->id]);
    }

    public function test_toggle_enabled(): void
    {
        $project = Project::factory()->create();
        $threshold = Threshold::factory()->for($project)->create(['enabled' => true]);

        session(['active_project_id' => $project->id]);

        Livewire::test(ThresholdManager::class)
            ->call('toggle', $threshold->id);

        $this->assertFalse((bool) $threshold->fresh()->enabled);
    }

    public function test_cannot_mutate_other_project_threshold(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $threshold = Threshold::factory()->for($other)->create();

        session(['active_project_id' => $project->id]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ThresholdManager::class)->call('delete', $threshold->id);
    }
}
