<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActionStatus;
use App\Livewire\Shell\Sidebar;
use App\Models\MetricSample;
use App\Models\PendingAction;
use App\Models\Project;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_navigation_and_the_project_list(): void
    {
        $project = Project::factory()->create(['name' => 'Produksi MySQL']);

        Livewire::test(Sidebar::class)
            ->assertSee('Dashboard')
            ->assertSee('Chat diagnostik')
            ->assertSee('Persetujuan')
            ->assertSee('Threshold')
            ->assertSee('Pengaturan AI')
            ->assertSee('Produksi MySQL')
            ->assertSee('Project aktif');
    }

    public function test_shows_the_number_of_pending_approvals_of_the_active_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        PendingAction::factory()->for($project)->create(['status' => ActionStatus::Pending]);
        PendingAction::factory()->for($project)->create(['status' => ActionStatus::Denied]);
        PendingAction::factory()->for($other)->create(['status' => ActionStatus::Pending]);

        session(['active_project_id' => $project->id]);

        Livewire::test(Sidebar::class)->assertSeeHtml('<span class="count">1</span>');
    }

    public function test_switching_the_project_redirects_to_the_workspace(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Sidebar::class)
            ->set('projectId', $project->id)
            ->assertRedirect(route('switch-project', $project->id));
    }

    public function test_restores_the_demo_data(): void
    {
        $this->seed(DemoSeeder::class);

        $project = Project::query()->orderBy('created_at')->firstOrFail();
        $sample = MetricSample::where('project_id', $project->id)->firstOrFail();
        $before = MetricSample::where('project_id', $project->id)->count();

        $sample->delete();

        $this->assertSame($before - 1, MetricSample::where('project_id', $project->id)->count());

        Livewire::test(Sidebar::class)
            ->call('resetDemoData')
            ->assertSet('notice', 'Data demo dipulihkan.');

        $this->assertSame($before, MetricSample::where('project_id', $project->id)->count());
    }
}
