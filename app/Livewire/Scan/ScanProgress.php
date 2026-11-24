<?php

declare(strict_types=1);

namespace App\Livewire\Scan;

use App\Enums\ScanStatus;
use App\Jobs\ScanDatabase;
use App\Models\Project;
use App\Models\ScanRun;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Starts a schema scan of the active project on the queue and shows its
 * progress. While the scan runs, the view polls every second (wire:poll);
 * once it is done or failed the polling element is gone and requests stop.
 */
#[Title('Scan skema — Agentic DB Monitor')]
class ScanProgress extends Component
{
    #[Locked]
    public ?string $projectId = null;

    public function mount(): void
    {
        $projectId = session('active_project_id');

        $this->projectId = $projectId !== null && Project::whereKey($projectId)->exists()
            ? (string) $projectId
            : null;
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->projectId === null ? null : Project::find($this->projectId);
    }

    /** The user's latest scan of the active project; only their own. */
    #[Computed]
    public function run(): ?ScanRun
    {
        if ($this->projectId === null) {
            return null;
        }

        return ScanRun::query()
            ->where('user_id', auth()->id())
            ->where('project_id', $this->projectId)
            ->latest()
            ->latest('id')
            ->first();
    }

    public function start(): void
    {
        if ($this->projectId === null || ($this->run !== null && ! $this->run->status->isFinished())) {
            return;
        }

        $run = ScanRun::create([
            'user_id' => auth()->id(),
            'project_id' => $this->projectId,
            'status' => ScanStatus::Queued,
        ]);

        ScanDatabase::dispatch($run->id);

        unset($this->run);
    }

    public function render(): View
    {
        return view('livewire.scan.scan-progress');
    }
}
