<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\ActionStatus;
use App\Models\ActionLog;
use App\Models\PendingAction;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
#[Lazy]
class HealthDashboard extends Component
{
    public const RANGES = [1, 6, 24, 48];

    #[Url]
    public int $hours = 24;

    public function mount(): void
    {
        $this->setRange($this->hours);
    }

    public function setRange(int $hours): void
    {
        $this->hours = in_array($hours, self::RANGES, true) ? $hours : 24;
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::find(session('active_project_id'));
    }

    /**
     * @return Collection<int, Threshold>
     */
    #[Computed]
    public function thresholds(): Collection
    {
        return $this->project?->thresholds()->orderBy('key')->get() ?? collect();
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     latest: float,
     *     captured_at: Carbon,
     *     values: Collection<int, float>,
     *     limit: float|null,
     *     breached: Collection<int, Threshold>
     * }>
     */
    #[Computed]
    public function metrics(): Collection
    {
        if ($this->project === null) {
            return collect();
        }

        $limits = $this->thresholds->where('enabled', true)->groupBy('key');

        return $this->project->metricSamples()
            ->where('captured_at', '>=', now()->subHours($this->hours))
            ->orderBy('captured_at')
            ->get(['key', 'value', 'captured_at'])
            ->groupBy('key')
            ->sortKeys()
            ->map(function (Collection $samples, string $key) use ($limits): array {
                $latest = $samples->last();
                $keyLimits = $limits->get($key, collect());

                return [
                    'key' => $key,
                    'latest' => $latest->value,
                    'captured_at' => $latest->captured_at,
                    'values' => $samples->pluck('value'),
                    'limit' => $keyLimits->min('value'),
                    'breached' => $keyLimits
                        ->filter(fn (Threshold $threshold): bool => $threshold->isBreachedBy($latest->value))
                        ->values(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, PendingAction>
     */
    #[Computed]
    public function pendingActions(): Collection
    {
        return $this->project?->pendingActions()
            ->where('status', ActionStatus::Pending)
            ->latest('proposed_at')
            ->get() ?? collect();
    }

    /**
     * @return Collection<int, ActionLog>
     */
    #[Computed]
    public function recentLogs(): Collection
    {
        return $this->project?->actionLogs()->latest()->limit(8)->get() ?? collect();
    }

    public function placeholder(): View
    {
        return view('livewire.dashboard.health-dashboard-placeholder');
    }

    public function render(): View
    {
        return view('livewire.dashboard.health-dashboard');
    }
}
