<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ActionDecision;
use App\Enums\DbDriver;
use App\Livewire\Projects\ProjectManager;
use App\Models\ActionLog;
use App\Models\ChatSession;
use App\Models\MetricSample;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_the_projects(): void
    {
        Project::factory()->create(['name' => 'Produksi MySQL']);

        Livewire::test(ProjectManager::class)
            ->assertSee('Kelola project')
            ->assertSee('Produksi MySQL');
    }

    public function test_edits_the_connection_details_of_a_project(): void
    {
        $project = Project::factory()->create([
            'name' => 'Lama',
            'driver' => DbDriver::MySql,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
        ]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->assertSet('name', 'Lama')
            ->set('name', 'Produksi')
            ->set('host', 'db.internal')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Project "Produksi" diperbarui.');

        $project->refresh();

        $this->assertSame('Produksi', $project->name);
        $this->assertSame('db.internal', $project->host);
    }

    public function test_keeps_the_stored_password_when_the_field_is_left_empty(): void
    {
        $project = Project::factory()->create(['password' => 'original-secret']);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('name', 'Tetap sama')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('original-secret', $project->refresh()->password);
    }

    public function test_replaces_the_password_when_a_new_one_is_typed(): void
    {
        $project = Project::factory()->create(['password' => 'original-secret']);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->set('password', 'new-secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('new-secret', $project->refresh()->password);
    }

    public function test_rejects_a_duplicate_connection_tuple(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        Livewire::test(ProjectManager::class)
            ->call('edit', $other->id)
            ->set('host', $project->host)
            ->set('port', $project->port)
            ->set('database', $project->database)
            ->set('username', $project->username)
            ->set('driver', $project->driver->value)
            ->call('save')
            ->assertSet('errorMessage', 'A database profile with these exact connection details already exists.');

        $this->assertNotSame($project->database, $other->refresh()->database);
        $this->assertNotSame($project->username, $other->username);
    }

    public function test_deletes_a_project_and_keeps_the_audit_log(): void
    {
        $project = Project::factory()->create();

        ChatSession::factory()->for($project)->create();
        MetricSample::factory()->for($project)->create();
        Threshold::factory()->for($project)->create();

        ActionLog::factory()->for($project)->create([
            'decision' => ActionDecision::Executed,
            'project_name' => $project->name,
        ]);

        session(['active_project_id' => $project->id]);

        Livewire::test(ProjectManager::class)
            ->call('edit', $project->id)
            ->call('delete');

        $this->assertNull(Project::find($project->id));
        $this->assertSame(0, MetricSample::where('project_id', $project->id)->count());
        $this->assertSame(0, Threshold::where('project_id', $project->id)->count());
        $this->assertNull(session('active_project_id'));

        $log = ActionLog::firstOrFail();
        $this->assertNull($log->project_id);
        $this->assertSame($project->name, $log->project_name);
    }
}
