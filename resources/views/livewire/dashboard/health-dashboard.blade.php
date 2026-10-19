<x-slot:subtitle>
    {{ $this->project?->name ?? '—' }}
    @if ($this->project)
        · {{ $this->project->driver->value }}
        · {{ $this->source['label'] }}
        @if ($this->source['detail'])
            · {{ $this->source['detail'] }}
        @endif
    @endif
</x-slot:subtitle>

<x-slot:actions>
    <div class="od-cluster" style="gap:6px">
        @foreach (App\Livewire\Dashboard\HealthDashboard::RANGES as $minutes => $label)
            <button type="button"
                class="btn sm {{ $this->minutes === $minutes ? 'primary' : 'ghost' }}"
                wire:click="setRange({{ $minutes }})">{{ $label }}</button>
        @endforeach
    </div>
</x-slot:actions>

@if ($this->project === null)
    <div class="card od-stack" style="gap:12px;align-items:center;padding:56px 24px;text-align:center">
        <svg class="od-icon" style="width:34px;height:34px;color:var(--adbm-muted)"><use href="#i-db" /></svg>
        <div class="od-stack" style="gap:4px">
            <h2 class="card-title">Belum ada project dipilih</h2>
            <p class="muted small">Tambahkan profil database atau pilih project dari sidebar.</p>
        </div>
        <flux:modal.trigger name="create-project-modal">
            <button type="button" class="btn primary">
                <svg class="od-icon"><use href="#i-plus" /></svg>
                Tambah koneksi database
            </button>
        </flux:modal.trigger>
    </div>
@else
    @php
        $breached = collect($this->kpis)->filter(fn (array $kpi): bool => $kpi['breached']);
    @endphp

    <div class="od-stack" style="gap:18px">
        <div class="od-row" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
            <h2 class="card-title">{{ $this->project->name }}</h2>

            <div class="od-cluster" style="gap:6px">
                <span class="badge">{{ $this->project->driver->value }}</span>
                <span class="badge {{ $this->source['replay'] ? 'info' : 'ok' }}">{{ $this->source['label'] }}</span>
                @if ($this->source['detail'])
                    <span class="badge">{{ $this->source['detail'] }}</span>
                @endif
                <span class="badge">Rentang {{ App\Livewire\Dashboard\HealthDashboard::RANGES[$this->minutes] }}</span>
            </div>
        </div>

        @if ($breached->isNotEmpty())
            <div class="alert">
                <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                <div>
                    <strong>{{ $breached->count() }} metrik melewati threshold</strong>
                    <span class="muted small">{{ $breached->pluck('label')->implode(', ') }}</span>
                </div>
            </div>
        @endif

        <section class="grid-kpi">
            @forelse ($this->kpis as $kpi)
                <div class="card od-stack" style="gap:10px;padding:14px 16px">
                    <div class="od-row" style="justify-content:space-between;gap:8px">
                        <span class="label">{{ $kpi['label'] }}</span>
                        <span class="badge {{ $kpi['breached'] ? 'breach' : 'ok' }}">
                            {{ $kpi['breached'] ? 'Breach' : 'OK' }}
                        </span>
                    </div>

                    <div class="od-row" style="align-items:baseline;gap:6px">
                        <span class="mono" style="font-size:24px;font-weight:600;color:{{ $kpi['breached'] ? 'var(--adbm-danger)' : 'var(--adbm-text)' }}">
                            {{ $kpi['value'] === null ? '—' : number_format($kpi['value'], 1) }}
                        </span>
                        <span class="muted small">{{ $kpi['unit'] }}</span>
                    </div>

                    @if ($kpi['secondary'] !== null)
                        <span class="muted small">
                            {{ $kpi['secondary']['label'] }}:
                            <span class="mono">{{ $kpi['secondary']['value'] === null ? '—' : number_format($kpi['secondary']['value'], 1) }}</span>
                        </span>
                    @endif

                    <div style="height:38px;color:{{ $kpi['breached'] ? 'var(--adbm-danger)' : 'var(--adbm-accent)' }}">
                        <x-dashboard.metric-chart :values="$kpi['values']" :limit="$kpi['limit']" />
                    </div>
                </div>
            @empty
                <div class="card od-stack" style="gap:6px;align-items:center;grid-column:1/-1;text-align:center">
                    <p class="muted small">Belum ada metrik yang dikumpulkan. Kolektor berjalan setiap 5 menit.</p>
                </div>
            @endforelse
        </section>

        @if ($this->signals !== [])
            <section class="card od-stack" style="gap:12px">
                <div class="od-row" style="justify-content:space-between;gap:12px">
                    <h2 class="card-title">Sinyal terkuat di jendela anomali</h2>
                    <span class="badge info">{{ $this->source['label'] }}</span>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Metrik</th>
                            <th>Normal</th>
                            <th>Anomali</th>
                            <th>Deviasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->signals as $signal)
                            <tr>
                                <td>{{ $signal['label'] }}</td>
                                <td class="mono">{{ number_format($signal['baseline'], 1) }}</td>
                                <td class="mono">{{ number_format($signal['window'], 1) }}</td>
                                <td class="mono" style="color:{{ $signal['deviation'] > 0 ? 'var(--adbm-danger)' : 'var(--adbm-info)' }}">
                                    {{ $signal['deviation'] > 0 ? '+' : '' }}{{ number_format($signal['deviation'], 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        @if ($this->series->isNotEmpty())
            <section class="od-stack" style="gap:12px">
                <h2 class="card-title">Grafik metrik</h2>

                <div class="grid-2">
                    @foreach ($this->series as $key => $entry)
                        @php
                            $metricKey = App\Enums\MetricKey::tryFrom((string) $key);
                            $limit = $this->thresholds->where('enabled', true)->where('key', $key)->min('value');
                            $isBreached = $this->thresholds->where('enabled', true)->where('key', $key)
                                ->contains(fn (App\Models\Threshold $threshold): bool => $threshold->isBreachedBy((float) $entry['latest']));
                        @endphp

                        <div class="card od-stack" style="gap:10px">
                            <div class="od-row" style="justify-content:space-between;gap:8px">
                                <span class="label">{{ $metricKey?->displayName() ?? $key }}</span>
                                <span class="badge {{ $isBreached ? 'breach' : 'ok' }}">{{ $isBreached ? 'Breach' : 'OK' }}</span>
                            </div>

                            <div class="od-row" style="align-items:baseline;gap:8px">
                                <span class="mono" style="font-size:20px;font-weight:600">{{ number_format($entry['latest'], 1) }}</span>
                                <span class="muted small">min {{ number_format($entry['min'], 1) }} · maks {{ number_format($entry['max'], 1) }} · {{ $entry['samples'] }} sampel</span>
                            </div>

                            <div style="height:120px;color:{{ $isBreached ? 'var(--adbm-danger)' : 'var(--adbm-accent)' }}">
                                <x-dashboard.metric-chart :values="$entry['values']" :limit="$limit" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid-2">
            <div class="card od-stack" style="gap:12px">
                <h2 class="card-title">Persetujuan tertunda</h2>

                @if ($this->pendingActions->isEmpty())
                    <p class="muted small">Tidak ada usulan yang menunggu persetujuan.</p>
                @else
                    <table class="table">
                        <tbody>
                            @foreach ($this->pendingActions as $action)
                                <tr>
                                    <td class="mono" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $action->command }}</td>
                                    <td><span class="badge">{{ $action->category->value }}</span></td>
                                    <td class="muted small">{{ $action->proposed_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card od-stack" style="gap:12px">
                <div class="od-row" style="justify-content:space-between;gap:12px">
                    <h2 class="card-title">Threshold</h2>
                    <a class="btn sm ghost" href="{{ route('thresholds') }}">
                        <svg class="od-icon"><use href="#i-edit" /></svg>
                        Ubah
                    </a>
                </div>

                @if ($this->thresholds->isEmpty())
                    <p class="muted small">Belum ada threshold untuk project ini.</p>
                @else
                    <table class="table">
                        <tbody>
                            @foreach ($this->thresholds as $threshold)
                                <tr>
                                    <td>{{ App\Enums\MetricKey::tryFrom($threshold->key)?->displayName() ?? $threshold->key }}</td>
                                    <td class="mono">{{ $threshold->operator->symbol() }} {{ number_format($threshold->value, 1) }}</td>
                                    <td>
                                        <span class="badge {{ $threshold->enabled ? 'ok' : '' }}">
                                            {{ $threshold->enabled ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        <section class="card od-stack" style="gap:12px">
            <h2 class="card-title">Aksi terakhir</h2>

            @if ($this->recentLogs->isEmpty())
                <p class="muted small">Belum ada aksi yang tercatat.</p>
            @else
                <table class="table">
                    <tbody>
                        @foreach ($this->recentLogs as $log)
                            <tr>
                                <td class="mono" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $log->command }}</td>
                                <td>
                                    <span class="badge {{ match ($log->decision->value) {
                                        'executed', 'approved' => 'ok',
                                        'denied' => '',
                                        'failed' => 'breach',
                                        default => '',
                                    } }}">{{ $log->decision->value }}</span>
                                </td>
                                <td class="muted small">{{ $log->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endif
