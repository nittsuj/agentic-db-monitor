<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\ActionStatus;
use App\Enums\MetricKey;
use App\Models\ActionLog;
use App\Models\PendingAction;
use App\Models\Project;
use App\Models\Threshold;
use App\Services\Monitoring\MetricSeriesService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard — Agentic DB Monitor')]
#[Lazy]
class HealthDashboard extends Component
{
    /**
     * Selectable ranges in minutes. The two short ranges keep the raw
     * five-second samples, the longer ones aggregate per minute.
     */
    public const RANGES = [
        5 => '5m',
        15 => '15m',
        60 => '1j',
        360 => '6j',
        1440 => '24j',
        2880 => '48j',
    ];

    public const DEFAULT_RANGE = 1440;

    /**
     * The KPI cards of the dashboard specification, in order.
     *
     * @var array<int, array{key: string, secondary: string|null}>
     */
    public const KPI_CARDS = [
        ['key' => 'connection_pool_used', 'secondary' => null],
        ['key' => 'active_connections', 'secondary' => null],
        ['key' => 'locks', 'secondary' => null],
        ['key' => 'long_running_queries', 'secondary' => null],
        ['key' => 'lock_waiting_sessions', 'secondary' => null],
        ['key' => 'cpu_user_pct', 'secondary' => null],
        ['key' => 'load_1m', 'secondary' => null],
        ['key' => 'commits', 'secondary' => 'rollback_ratio'],
        ['key' => 'buffer_hit_ratio', 'secondary' => null],
    ];

    #[Url]
    public int $minutes = self::DEFAULT_RANGE;

    public function mount(): void
    {
        $this->setRange($this->minutes);
    }

    public function setRange(int $minutes): void
    {
        $this->minutes = array_key_exists($minutes, self::RANGES) ? $minutes : self::DEFAULT_RANGE;
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
     * The metric series per key, used by the chart panels.
     *
     * @return Collection<string, array<string, mixed>>
     */
    #[Computed]
    public function series(): Collection
    {
        if ($this->project === null) {
            return collect();
        }

        return app(MetricSeriesService::class)->series($this->project, $this->minutes);
    }

    /**
     * The KPI row of the dashboard specification.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function kpis(): array
    {
        if ($this->project === null) {
            return [];
        }

        $series = $this->series;
        $limits = $this->thresholds->where('enabled', true)->groupBy('key');
        $driver = $this->project->driver;
        $cards = [];

        foreach (self::KPI_CARDS as $card) {
            $metricKey = MetricKey::tryFrom($card['key']);

            if ($metricKey === null || ! $metricKey->availableFor($driver)) {
                continue;
            }

            $entry = $series->get($card['key']);
            $secondary = $card['secondary'] === null ? null : $series->get($card['secondary']);
            $thresholds = $limits->get($card['key'], collect());

            $cards[] = [
                'key' => $card['key'],
                'label' => $metricKey->displayName(),
                'unit' => $metricKey->unit(),
                'value' => $entry['latest'] ?? null,
                'values' => $entry['values'] ?? collect(),
                'secondary' => $secondary === null ? null : [
                    'key' => $card['secondary'],
                    'label' => MetricKey::tryFrom($card['secondary'])?->displayName() ?? $card['secondary'],
                    'value' => $secondary['latest'] ?? null,
                ],
                'limit' => $thresholds->min('value'),
                'breached' => $entry === null
                    ? false
                    : $thresholds->contains(fn (Threshold $threshold): bool => $threshold->isBreachedBy((float) $entry['latest'])),
                'warns' => $metricKey->higherIsWorse(),
            ];
        }

        return $cards;
    }

    /**
     * Where the numbers come from: live collection or a DBPA replay.
     *
     * @return array{label: string, detail: string|null, replay: bool}
     */
    #[Computed]
    public function source(): array
    {
        $sample = $this->project?->metricSamples()->latest('captured_at')->first();
        $meta = $sample?->meta ?? [];

        if (($meta['source'] ?? null) === 'dbpa') {
            $detail = collect([$meta['scenario'] ?? null, $meta['config'] ?? null])
                ->filter()
                ->implode(' · ');

            return [
                'label' => 'Replay DBPA',
                'detail' => $detail === '' ? null : $detail,
                'replay' => true,
            ];
        }

        return [
            'label' => 'Live',
            'detail' => null,
            'replay' => false,
        ];
    }

    /**
     * The five strongest signals of the anomaly window.
     *
     * @return array<int, array{key: string, label: string, deviation: float, baseline: float, window: float}>
     */
    #[Computed]
    public function signals(): array
    {
        if ($this->project === null) {
            return [];
        }

        return app(MetricSeriesService::class)->anomalySignals($this->project, $this->minutes);
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
        return view('livewire.dashboard.health-dashboard', [
            'lastSampleAt' => $this->series->max('captured_at'),
        ]);
    }
}
