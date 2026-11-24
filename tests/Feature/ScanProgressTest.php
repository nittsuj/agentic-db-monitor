<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ScanDatabase;
use App\Livewire\Scan\ScanProgress;
use App\Models\Project;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ScanProgressTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->alice = User::factory()->create();
        session(['active_project_id' => $this->project->id]);
    }

    public function test_the_page_needs_a_login(): void
    {
        $this->get(route('scan'))->assertRedirect(route('login'));
    }

    public function test_starting_queues_a_scan_of_the_active_project(): void
    {
        Queue::fake();

        Livewire::actingAs($this->alice)->test(ScanProgress::class)
            ->assertSee('Mulai scan')
            ->call('start')
            ->assertSee('Menunggu worker antrean');

        $run = ScanRun::sole();
        $this->assertSame($this->alice->id, $run->user_id);
        $this->assertSame($this->project->id, $run->project_id);
        $this->assertSame(ScanStatus::Queued, $run->status);
        Queue::assertPushed(ScanDatabase::class, fn (ScanDatabase $job): bool => $job->scanRunId === $run->id);
    }

    public function test_a_second_scan_cannot_start_while_one_is_running(): void
    {
        Queue::fake();
        ScanRun::factory()->for($this->project)->for($this->alice)->create(['status' => ScanStatus::Running]);

        Livewire::actingAs($this->alice)->test(ScanProgress::class)->call('start');

        $this->assertSame(1, ScanRun::count());
        Queue::assertNothingPushed();
    }

    public function test_polls_while_running_and_shows_the_current_table(): void
    {
        ScanRun::factory()->for($this->project)->for($this->alice)->create([
            'status' => ScanStatus::Running, 'total' => 4, 'processed' => 1, 'current_table' => 'orders',
        ]);

        Livewire::actingAs($this->alice)->test(ScanProgress::class)
            ->assertSeeHtml('wire:poll.1s')
            ->assertSeeHtml('aria-valuenow="25"')
            ->assertSee('orders')
            ->assertSee('(1/4)');
    }

    public function test_follows_the_scan_and_stops_polling_when_it_is_done(): void
    {
        $run = ScanRun::factory()->for($this->project)->for($this->alice)->create(['status' => ScanStatus::Running, 'total' => 2, 'processed' => 1]);

        $component = Livewire::actingAs($this->alice)->test(ScanProgress::class)->assertSeeHtml('wire:poll.1s');

        // The worker finishes between two polls.
        $run->update([
            'status' => ScanStatus::Done, 'processed' => 2,
            'findings' => [['table' => 'audit_log', 'issues' => ['Tidak punya primary key']]],
        ]);

        $component->call('$refresh')
            ->assertDontSeeHtml('wire:poll.1s')
            ->assertSeeHtml('aria-valuenow="100"')
            ->assertSee('audit_log')
            ->assertSee('Tidak punya primary key')
            ->assertSee('Scan ulang');
    }

    public function test_a_clean_scan_says_so(): void
    {
        ScanRun::factory()->for($this->project)->for($this->alice)->create(['status' => ScanStatus::Done, 'total' => 3, 'processed' => 3, 'findings' => []]);

        Livewire::actingAs($this->alice)->test(ScanProgress::class)->assertSee('Tidak ada masalah ditemukan.');
    }

    public function test_a_failed_scan_shows_why_and_stops_polling(): void
    {
        ScanRun::factory()->for($this->project)->for($this->alice)->create(['status' => ScanStatus::Failed, 'error' => 'Connection refused']);

        Livewire::actingAs($this->alice)->test(ScanProgress::class)
            ->assertSee('Connection refused')
            ->assertDontSeeHtml('wire:poll.1s');
    }

    public function test_never_shows_another_users_scan(): void
    {
        ScanRun::factory()->for($this->project)->for(User::factory())->create([
            'status' => ScanStatus::Done, 'findings' => [['table' => 'bob_secret_table', 'issues' => ['Tidak punya primary key']]],
        ]);

        Livewire::actingAs($this->alice)->test(ScanProgress::class)
            ->assertDontSee('bob_secret_table')
            ->assertSee('Belum pernah di-scan');
    }

    public function test_without_an_active_project_nothing_can_start(): void
    {
        Queue::fake();
        session()->forget('active_project_id');

        Livewire::actingAs($this->alice)->test(ScanProgress::class)
            ->assertSee('Pilih project dulu')
            ->call('start');

        $this->assertSame(0, ScanRun::count());
        Queue::assertNothingPushed();
    }

    public function test_the_page_renders_with_the_sidebar_link(): void
    {
        $this->withoutVite();

        $this->actingAs($this->alice)->get(route('scan'))
            ->assertOk()
            ->assertSeeLivewire(ScanProgress::class)
            ->assertSee('Scan skema');
    }
}
