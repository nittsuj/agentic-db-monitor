<div class="space-y-6" wire:poll.60s>
    @if ($this->project === null)
        <flux:card class="flex flex-col items-center gap-4 py-16 text-center">
            <flux:icon.circle-stack class="size-10 text-zinc-400" />
            <div class="space-y-1">
                <flux:heading size="lg">No workspace selected</flux:heading>
                <flux:text>Add a database profile or pick one from the sidebar.</flux:text>
            </div>
            <flux:modal.trigger name="create-project-modal">
                <flux:button variant="primary" icon="plus">
                    Add Database Connection
                </flux:button>
            </flux:modal.trigger>
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
                    {{ $breached->count() }} {{ \Illuminate\Support\Str::plural('metric', $breached->count()) }} over threshold
                </flux:callout.heading>
                <flux:callout.text>
                    {{ $breached->map(fn ($metric) => \Illuminate\Support\Str::headline($metric['key']))->join(', ') }}
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
                            <flux:text>{{ \Illuminate\Support\Str::headline($metric['key']) }}</flux:text>
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
                                    <flux:table.cell variant="strong">{{ \Illuminate\Support\Str::headline($threshold->key) }}</flux:table.cell>
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
                                        \App\Enums\ActionDecision::Executed, \App\Enums\ActionDecision::Approved => 'green',
                                        \App\Enums\ActionDecision::Denied => 'zinc',
                                        \App\Enums\ActionDecision::Failed => 'red',
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
