# Health Dashboard — Implementation with Flux UI

The health dashboard is the read-only landing screen of a workspace. For the active project it
shows the latest value and trend of every collected metric, which thresholds are currently
breached, the mutations waiting for approval, and the most recent audit entries.

- **Stories served:** US-02 (workspace isolation), US-07 (see what triggered an alert),
  US-10 (thresholds in context).
- **Stack:** Livewire 3 full-page component, Flux UI free components, Tailwind 4, inline SVG chart.
- **Data:** local SQLite only. The dashboard never queries a target database; it reads what
  `CollectMetricsJob` already persisted.

See also: [`high_level_design.md`](./high_level_design.md),
[`entity_relationship_diagram.md`](./entity_relationship_diagram.md),
[`sequence_diagram/monitoring_and_retention.md`](./sequence_diagram/monitoring_and_retention.md).

---

## 1. Prerequisites

| Needed | Comes from | State today |
| --- | --- | --- |
| `livewire/livewire` 3.7+, `livewire/flux` 2.x | Phase 0 | not installed |
| Tailwind CSS 4.2+ (Flux 2 requirement) | `package.json` has `^4.0.0` | run `npm update tailwindcss @tailwindcss/vite` |
| Models `Project`, `MetricSample`, `Threshold`, `PendingAction`, `ActionLog` + enums | Phase 1 (§3.2–3.3 of the plan) | not written |
| Active-project resolution | Phase 1 sidebar switcher | not written |
| Real samples | Phase 4 `CollectMetricsJob` | not written; `DemoSeeder` data is enough to build against |

The dashboard can be built as soon as Phase 0 and the Phase 1 models exist; it does not need
the collector, because `DemoSeeder` already writes 96 samples per metric per project.

### 1.1 Install and wire Flux

```bash
composer require livewire/livewire:^3.7 livewire/flux
```

`resources/css/app.css`:

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';

@custom-variant dark (&:where(.dark, .dark *));
```

Keep the existing `@source` and `@theme` blocks below these lines.

### 1.2 Flux components used

Everything below ships in the free `livewire/flux` package.

| Purpose | Component |
| --- | --- |
| Page shell | `flux:sidebar`, `flux:main` |
| Titles and body text | `flux:heading`, `flux:text` |
| Panels | `flux:card` |
| Status chips | `flux:badge` |
| Breach banner | `flux:callout` |
| Range switch | `flux:button.group`, `flux:button` |
| Lists | `flux:table` |
| Loading state | `flux:skeleton` |
| Empty-state glyphs | `flux:icon` |

Two components that look like the obvious choice are **Pro-only and must not be used**:
`flux:chart` (replaced by the inline SVG in §4.3) and `flux:tabs` (replaced by the button group).

---

## 2. Screen layout

```
┌──────────┬──────────────────────────────────────────────────────┐
│ sidebar  │ Playground MySQL  [mysql]        [1h][6h][24h][48h]  │
│          │ Last sample 3 minutes ago                            │
│ projects │ ┌──────────────────────────────────────────────────┐ │
│          │ │ ⚠ 1 threshold breached: Connection Pool Used     │ │
│ Dashboard│ └──────────────────────────────────────────────────┘ │
│ Chat     │ ┌───────────────────────┐ ┌───────────────────────┐  │
│ Settings │ │ Connection Pool Used  │ │ Slow Queries Per Min  │  │
│          │ │ 84.2        [Breach]  │ │ 3.1            [OK]   │  │
│          │ │ ╱╲__╱╲___╱╲ - - limit │ │ __╱╲____╱╲__ - - limit│  │
│          │ └───────────────────────┘ └───────────────────────┘  │
│          │ ┌───────────────────────┐ ┌───────────────────────┐  │
│          │ │ Pending approvals     │ │ Thresholds            │  │
│          │ └───────────────────────┘ └───────────────────────┘  │
│          │ ┌──────────────────────────────────────────────────┐ │
│          │ │ Recent actions                                   │ │
│          │ └──────────────────────────────────────────────────┘ │
└──────────┴──────────────────────────────────────────────────────┘
```

Every section is scoped to one project. Switching workspace in the sidebar changes all of them.

---

## 3. Files

| File | Role |
| --- | --- |
| `app/Livewire/Dashboard/HealthDashboard.php` | Full-page component: range state + scoped queries |
| `resources/views/livewire/dashboard/health-dashboard.blade.php` | Dashboard view |
| `resources/views/livewire/dashboard/health-dashboard-placeholder.blade.php` | Skeleton shown while lazy-loading |
| `app/View/Components/Dashboard/MetricChart.php` | Turns a value series into SVG coordinates |
| `resources/views/components/dashboard/metric-chart.blade.php` | The SVG |
| `resources/views/components/layouts/app.blade.php` | Flux shell (shared with chat and settings) |
| `routes/web.php` | `GET /dashboard` |
| `tests/Feature/Livewire/HealthDashboardTest.php` | Component tests |
| `tests/Unit/MetricChartTest.php` | Coordinate maths |

Two small additions to Phase 1 code are needed, and Phase 4 reuses both for notifications:

```php
// app/Enums/ThresholdOperator.php
public function matches(float $sample, float $limit): bool
{
    return match ($this) {
        self::Gt => $sample > $limit,
        self::Gte => $sample >= $limit,
        self::Lt => $sample < $limit,
        self::Lte => $sample <= $limit,
        self::Eq => $sample === $limit,
        self::Ne => $sample !== $limit,
    };
}

public function symbol(): string
{
    return match ($this) {
        self::Gt => '>',
        self::Gte => '≥',
        self::Lt => '<',
        self::Lte => '≤',
        self::Eq => '=',
        self::Ne => '≠',
    };
}
```

```php
// app/Models/Threshold.php
public function isBreachedBy(float $sample): bool
{
    return $this->enabled && $this->operator->matches($sample, $this->value);
}
```

---

## 4. Implementation

### 4.1 Route and layout

```php
// routes/web.php
use App\Livewire\Dashboard\HealthDashboard;

Route::get('/dashboard', HealthDashboard::class)->name('dashboard');
```

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.nav>
            <flux:sidebar.item icon="chart-bar" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                Dashboard
            </flux:sidebar.item>
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
```

The project switcher, chat, and settings entries are added to this sidebar by their own phases.

### 4.2 Livewire component

```php
<?php

namespace App\Livewire\Dashboard;

use App\Enums\ActionStatus;
use App\Models\Project;
use App\Models\Threshold;
use Illuminate\Contracts\View\View;
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

    #[Computed]
    public function thresholds(): Collection
    {
        return $this->project?->thresholds()->orderBy('key')->get() ?? collect();
    }

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

    #[Computed]
    public function pendingActions(): Collection
    {
        return $this->project?->pendingActions()
            ->where('status', ActionStatus::Pending)
            ->latest('proposed_at')
            ->get() ?? collect();
    }

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
```

Notes:

- **Scoping.** Every query starts from `$this->project->…()`. There is no query that takes a
  project id from the request, so a tampered URL cannot read another workspace.
- **Range.** `#[Url]` keeps `?hours=6` shareable across reloads; `mount()` clamps it to `RANGES`.
- **Query path.** The sample query is served by the `(project_id, key, captured_at)` index. At
  the 5-minute cadence the 48h window is at most 576 rows per metric, so no bucketing is needed.
- **`limit`.** The chart draws one dashed line per metric at the lowest enabled threshold value.

### 4.3 Chart component (no Pro, no JS dependency)

```php
<?php

namespace App\View\Components\Dashboard;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class MetricChart extends Component
{
    public const WIDTH = 600;

    public const HEIGHT = 140;

    private float $min;

    private float $span;

    public function __construct(public Collection $values, public ?float $limit = null)
    {
        $all = $values->when($limit !== null, fn (Collection $v) => $v->concat([$limit]));

        $this->min = (float) min(0, $all->min() ?? 0);
        $this->span = ((float) ($all->max() ?? 0) - $this->min) ?: 1.0;
    }

    public function y(float $value): float
    {
        return round(self::HEIGHT - (($value - $this->min) / $this->span) * self::HEIGHT, 1);
    }

    public function points(): string
    {
        $step = self::WIDTH / max($this->values->count() - 1, 1);

        return $this->values->values()
            ->map(fn (float $value, int $i): string => round($i * $step, 1).','.$this->y($value))
            ->implode(' ');
    }

    public function render(): View
    {
        return view('components.dashboard.metric-chart');
    }
}
```

```blade
{{-- resources/views/components/dashboard/metric-chart.blade.php --}}
<svg {{ $attributes->class('h-36 w-full') }}
     viewBox="0 0 {{ $component::WIDTH }} {{ $component::HEIGHT }}"
     preserveAspectRatio="none" role="img" aria-label="Metric trend">
    @if ($limit !== null)
        <line x1="0" x2="{{ $component::WIDTH }}" y1="{{ $y($limit) }}" y2="{{ $y($limit) }}"
              class="stroke-red-500" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
    @endif

    <polyline points="{{ $points() }}" fill="none" class="stroke-current"
              stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
</svg>
```

The line uses `currentColor`, so the caller picks the colour with a text class and dark mode
works without extra rules.

### 4.4 Dashboard view

```blade
{{-- resources/views/livewire/dashboard/health-dashboard.blade.php --}}
<div class="space-y-6" wire:poll.60s>
    @if ($this->project === null)
        <flux:card class="flex flex-col items-center gap-3 py-16 text-center">
            <flux:icon.circle-stack class="size-10 text-zinc-400" />
            <flux:heading size="lg">No workspace selected</flux:heading>
            <flux:text>Add a database profile or pick one from the sidebar.</flux:text>
        </flux:card>
    @else
        @php
            $breached = $this->metrics->filter(fn ($metric) => $metric['breached']->isNotEmpty());
        @endphp

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" level="1">{{ $this->project->name }}</flux:heading>
                    <flux:badge size="sm">{{ $this->project->driver->value }}</flux:badge>
                </div>
                <flux:text>
                    @if ($this->metrics->isNotEmpty())
                        Last sample {{ $this->metrics->max('captured_at')->diffForHumans() }}
                    @else
                        No samples in the last {{ $hours }}h
                    @endif
                </flux:text>
            </div>

            <flux:button.group>
                @foreach ($this::RANGES as $range)
                    <flux:button size="sm" wire:click="setRange({{ $range }})"
                                 :variant="$hours === $range ? 'primary' : 'outline'">
                        {{ $range }}h
                    </flux:button>
                @endforeach
            </flux:button.group>
        </div>

        @if ($breached->isNotEmpty())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>
                    {{ $breached->count() }} {{ Str::plural('metric', $breached->count()) }} over threshold
                </flux:callout.heading>
                <flux:callout.text>
                    {{ $breached->map(fn ($metric) => Str::headline($metric['key']))->join(', ') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            @forelse ($this->metrics as $metric)
                @php
                    $isBreached = $metric['breached']->isNotEmpty();
                @endphp

                <flux:card wire:key="metric-{{ $metric['key'] }}" class="space-y-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <flux:text>{{ Str::headline($metric['key']) }}</flux:text>
                            <flux:heading size="xl" class="tabular-nums">
                                {{ number_format($metric['latest'], 1) }}
                            </flux:heading>
                        </div>
                        <flux:badge size="sm" :color="$isBreached ? 'red' : 'green'">
                            {{ $isBreached ? 'Breach' : 'OK' }}
                        </flux:badge>
                    </div>

                    <x-dashboard.metric-chart :values="$metric['values']" :limit="$metric['limit']"
                                              @class([$isBreached ? 'text-red-500' : 'text-sky-500']) />
                </flux:card>
            @empty
                <flux:card class="py-12 text-center md:col-span-2">
                    <flux:text>No metrics collected yet. The collector runs every 5 minutes.</flux:text>
                </flux:card>
            @endforelse
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card class="space-y-4">
                <flux:heading size="lg">Pending approvals</flux:heading>

                @if ($this->pendingActions->isEmpty())
                    <flux:text>Nothing is waiting for approval.</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Command</flux:table.column>
                            <flux:table.column>Category</flux:table.column>
                            <flux:table.column>Proposed</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->pendingActions as $action)
                                <flux:table.row :key="$action->id">
                                    <flux:table.cell variant="strong" class="font-mono">{{ $action->command }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="amber">{{ $action->category->value }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $action->proposed_at->diffForHumans() }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>

            <flux:card class="space-y-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Thresholds</flux:heading>
                    <flux:button size="sm" variant="ghost" href="/settings/thresholds">Edit</flux:button>
                </div>

                @if ($this->thresholds->isEmpty())
                    <flux:text>No thresholds configured for this project.</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Metric</flux:table.column>
                            <flux:table.column>Rule</flux:table.column>
                            <flux:table.column>State</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->thresholds as $threshold)
                                <flux:table.row :key="$threshold->id">
                                    <flux:table.cell variant="strong">{{ Str::headline($threshold->key) }}</flux:table.cell>
                                    <flux:table.cell class="tabular-nums">
                                        {{ $threshold->operator->symbol() }} {{ number_format($threshold->value, 1) }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$threshold->enabled ? 'green' : 'zinc'">
                                            {{ $threshold->enabled ? 'Enabled' : 'Disabled' }}
                                        </flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>

        <flux:card class="space-y-4">
            <flux:heading size="lg">Recent actions</flux:heading>

            @if ($this->recentLogs->isEmpty())
                <flux:text>No actions recorded yet.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Command</flux:table.column>
                        <flux:table.column>Decision</flux:table.column>
                        <flux:table.column>When</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->recentLogs as $log)
                            <flux:table.row :key="$log->id">
                                <flux:table.cell variant="strong" class="font-mono">{{ $log->command }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="match ($log->decision) {
                                        App\Enums\ActionDecision::Executed, App\Enums\ActionDecision::Approved => 'green',
                                        App\Enums\ActionDecision::Denied => 'zinc',
                                        App\Enums\ActionDecision::Failed => 'red',
                                    }">{{ $log->decision->value }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ $log->created_at->diffForHumans() }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endif
</div>
```

### 4.5 Loading placeholder

```blade
{{-- resources/views/livewire/dashboard/health-dashboard-placeholder.blade.php --}}
<div class="space-y-6">
    <flux:skeleton.group animate="shimmer">
        <flux:skeleton.line class="w-1/3" size="lg" />
        <div class="grid gap-6 md:grid-cols-2">
            <flux:skeleton class="h-56 rounded-xl" />
            <flux:skeleton class="h-56 rounded-xl" />
        </div>
        <flux:skeleton class="h-48 rounded-xl" />
    </flux:skeleton.group>
</div>
```

---

## 5. Behaviour

| Concern | Decision |
| --- | --- |
| Refresh | `wire:poll.60s` on the root element. Samples arrive every 5 minutes, so a faster poll only burns CPU in the desktop shell. |
| Breach state | Computed from the **latest** sample per metric against **enabled** thresholds. It is display-only; the OS notification stays in the Phase 4 worker. |
| Workspace switch | The sidebar switcher writes `active_project_id` to the session and redirects to `/dashboard`; computed properties re-resolve on the next request. |
| Writes | None. Approving a pending action happens in the chat approval modal, not here. |
| Dark mode | Handled by `@fluxAppearance` and Flux's own tokens; the chart inherits `currentColor`. |

---

## 6. Tests

`tests/Feature/Livewire/HealthDashboardTest.php` (SQLite in-memory, factories):

1. Shows the empty state when no project is active.
2. Shows the latest value of each metric for the active project.
3. Does not show samples, thresholds, pending actions, or logs that belong to another project.
4. Renders the breach callout when the latest sample violates an enabled threshold.
5. Does not render it for a disabled threshold, or when only an older sample was over the limit.
6. `setRange(6)` excludes a sample captured 7 hours ago; `?hours=999` falls back to 24.
7. Lists only `pending` actions under "Pending approvals".

```php
public function test_breach_callout_is_shown_for_the_latest_sample(): void
{
    $project = Project::factory()->create();
    Threshold::factory()->for($project)->create([
        'key' => 'connection_pool_used',
        'operator' => ThresholdOperator::Gt,
        'value' => 80,
    ]);
    MetricSample::factory()->for($project)->create([
        'key' => 'connection_pool_used',
        'value' => 91,
        'captured_at' => now()->subMinutes(2),
    ]);

    session(['active_project_id' => $project->id]);

    Livewire::withoutLazyLoading()
        ->test(HealthDashboard::class)
        ->assertSee('1 metric over threshold')
        ->assertSee('Connection Pool Used');
}
```

`tests/Unit/MetricChartTest.php`: the highest value maps to `y = 0`, zero maps to
`y = HEIGHT`, a single-value series does not divide by zero, and the limit is included in the
scale so the dashed line is always inside the viewBox.

---

## 7. Open points

- **Active project source.** This document assumes a session key `active_project_id`. The plan
  also has `projects.is_active`, whose meaning (selected workspace vs. profile enabled) is not
  defined anywhere. Whichever the sidebar switcher adopts, only `HealthDashboard::project()`
  changes.
- **Demo data ages out.** `DemoSeeder` anchors its series to the project's `created_at`, and
  re-seeding is idempotent, so a database seeded more than 48 hours ago shows an empty dashboard.
  Use `php artisan migrate:fresh --seed` when working on this screen.
- **`/settings/thresholds` link.** The target page belongs to Phase 4 (US-10); until it exists
  the "Edit" button leads to a 404.
- **Plan placement.** `implementation_plan.md` lists no dashboard task even though the HLD names
  the screen. It fits at the end of Phase 1 (models available) or the start of Phase 4.

## 8. Definition of done

1. `vendor/bin/pint` passes.
2. `php artisan test` passes, including the cases in §6.
3. `/dashboard` renders against `migrate:fresh --seed` data in light and dark mode.
4. No Flux Pro component is referenced (`flux:chart`, `flux:tabs`).
