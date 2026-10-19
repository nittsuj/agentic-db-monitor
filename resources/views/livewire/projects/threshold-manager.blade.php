<x-slot:subtitle>
    Ambang alert per metrik · berlaku untuk project aktif saja
</x-slot:subtitle>

<div class="od-stack" style="gap:18px">
    <section class="card od-stack" style="gap:12px">
        <div class="od-row" style="justify-content:space-between;gap:12px">
            <h2 class="card-title">Threshold aktif</h2>
            <span class="badge">{{ $thresholds->count() }} aturan</span>
        </div>

        @if ($thresholds->isEmpty())
            <p class="muted small">Belum ada threshold untuk project ini.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Metrik</th>
                        <th>Aturan</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($thresholds as $threshold)
                        <tr wire:key="threshold-{{ $threshold->id }}">
                            <td>{{ \App\Enums\MetricKey::tryFrom($threshold->key)?->displayName() ?? $threshold->key }}</td>
                            <td class="mono">{{ $threshold->operator->symbol() }} {{ number_format($threshold->value, 1) }}</td>
                            <td>
                                <button type="button" class="badge {{ $threshold->enabled ? 'ok' : '' }}"
                                        wire:click="toggle('{{ $threshold->id }}')"
                                        style="cursor:pointer">
                                    {{ $threshold->enabled ? 'Aktif' : 'Nonaktif' }}
                                </button>
                            </td>
                            <td>
                                <div class="od-row" style="gap:6px;justify-content:flex-end">
                                    <button type="button" class="btn sm ghost" wire:click="edit('{{ $threshold->id }}')">
                                        <svg class="od-icon"><use href="#i-edit" /></svg>
                                        Ubah
                                    </button>
                                    <button type="button" class="btn sm danger" wire:click="delete('{{ $threshold->id }}')"
                                            wire:confirm="Hapus threshold ini?">
                                        <svg class="od-icon"><use href="#i-trash" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card od-stack" style="gap:12px">
        <h2 class="card-title">{{ $editingId ? 'Ubah threshold' : 'Tambah threshold' }}</h2>

        <form wire:submit="save" class="od-stack" style="gap:12px">
            <div class="od-row" style="gap:12px;align-items:flex-end">
                <div class="od-stack" style="gap:6px;flex:2 1 0">
                    <label class="label" for="th-key">Metrik</label>
                    <select id="th-key" class="input" wire:model="key">
                        @foreach ($metricKeys as $metricKey)
                            <option value="{{ $metricKey->value }}">{{ $metricKey->displayName() }} · {{ $metricKey->value }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="od-stack" style="gap:6px;flex:1 1 0">
                    <label class="label" for="th-operator">Operator</label>
                    <select id="th-operator" class="input" wire:model="operator">
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->value }}">{{ $operator->value }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="od-stack" style="gap:6px;flex:1 1 0">
                    <label class="label" for="th-value">Nilai</label>
                    <input id="th-value" class="input" type="number" step="0.1" wire:model="value" required>
                </div>
            </div>

            <div class="od-row" style="gap:8px;justify-content:flex-end;border-top:1px solid var(--adbm-border);padding-top:14px">
                @if ($editingId)
                    <button type="button" class="btn ghost" wire:click="resetForm">Batal</button>
                @endif

                <button type="submit" class="btn primary">
                    {{ $editingId ? 'Simpan perubahan' : 'Tambah threshold' }}
                </button>
            </div>
        </form>
    </section>
</div>
