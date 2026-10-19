<x-slot:subtitle>
    {{ $this->activeProjectId === null ? 'Pilih project di sidebar' : 'Satu statements per usulan · eksekusi hanya setelah disetujui' }}
</x-slot:subtitle>

<div class="od-stack" style="gap:18px">
    <section class="od-stack" style="gap:12px">
        <div class="od-row" style="justify-content:space-between;gap:12px">
            <h2 class="card-title">Usulan menunggu persetujuan</h2>
            <span class="badge {{ count($actions) > 0 ? 'warn' : '' }}">{{ count($actions) }} usulan</span>
        </div>

        @forelse ($actions as $action)
            <div class="card od-stack" style="gap:12px">
                <div class="od-row" style="justify-content:space-between;gap:12px">
                    <div class="od-cluster" style="gap:8px">
                        <span class="badge info">{{ $action->category->value }}</span>
                        <span class="badge">{{ $action->engine }}</span>
                        <span class="muted small">diajukan {{ $action->proposed_at->diffForHumans() }}</span>
                    </div>
                </div>

                <pre class="code">{{ $action->command }}</pre>

                <div class="od-row" style="gap:8px;justify-content:flex-end">
                    <button type="button" class="btn danger" wire:click="deny('{{ $action->id }}')"
                            wire:confirm="Tolak usulan ini? Perintah tidak akan dijalankan.">
                        <svg class="od-icon"><use href="#i-x" /></svg>
                        Tolak
                    </button>

                    <button type="button" class="btn primary" wire:click="approve('{{ $action->id }}')"
                            wire:confirm="Jalankan perintah ini di database target?">
                        <svg class="od-icon"><use href="#i-check" /></svg>
                        Setujui
                    </button>
                </div>
            </div>
        @empty
            <div class="card od-stack" style="gap:8px;align-items:center;padding:40px 20px;text-align:center">
                <svg class="od-icon" style="width:28px;height:28px;color:var(--adbm-muted)"><use href="#i-shield" /></svg>
                <p class="muted small">Tidak ada usulan yang menunggu persetujuan.</p>
            </div>
        @endforelse
    </section>

    <section class="card od-stack" style="gap:12px">
        <h2 class="card-title">Riwayat keputusan</h2>

        @if ($history->isEmpty())
            <p class="muted small">Belum ada keputusan yang tercatat.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Perintah</th>
                        <th>Keputusan</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $log)
                        <tr>
                            <td class="mono" style="max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $log->command }}</td>
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
