<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActionStatus;
use App\Livewire\Shell\Sidebar;
use App\Models\MetricSample;
use App\Models\PendingAction;
use App\Models\Project;
use App\Models\User;
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

    public function test_the_badge_updates_when_approvals_change(): void
    {
        $project = Project::factory()->create();
        $action = PendingAction::factory()->for($project)->create(['status' => ActionStatus::Pending]);
        session(['active_project_id' => $project->id]);

        $sidebar = Livewire::test(Sidebar::class)->assertSeeHtml('<span class="count">1</span>');

        $action->update(['status' => ActionStatus::Denied]);

        $sidebar->dispatch('approvals-changed')
            ->assertDontSeeHtml('<span class="count">');
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

    public function test_shows_the_signed_in_user_and_a_logout_button(): void
    {
        $user = User::factory()->create(['name' => 'Sari Wulandari', 'email' => 'sari@example.com']);

        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->assertSee('Masuk sebagai')
            ->assertSee('Sari Wulandari')
            ->assertSee('sari@example.com')
            ->assertSeeHtml('action="'.route('logout').'"')
            ->assertSee('Keluar');
    }

    public function test_the_logout_button_signs_the_user_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
