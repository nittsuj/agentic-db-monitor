<x-slot:subtitle>
    Cek primary key dan index foreign key tiap tabel · {{ $this->project?->name ?? '—' }}
</x-slot:subtitle>

<div class="od-stack" style="gap:18px">
    @php($run = $this->run)

    {{-- Present only while the scan is queued or running: when it finishes this
         element leaves the page, and with it the once-a-second request. --}}
    @if ($run !== null && ! $run->status->isFinished())
        <div wire:poll.1s data-scan-polling hidden></div>
    @endif

    <section class="card od-stack" style="gap:14px">
        <div class="od-row" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
            <h2 class="card-title">Scan skema database</h2>

            <button type="button" class="btn primary" wire:click="start" wire:loading.attr="disabled" wire:target="start"
                    @disabled($this->project === null || ($run !== null && ! $run->status->isFinished()))>
                <svg class="od-icon"><use href="#i-play" /></svg>
                {{ $run === null ? 'Mulai scan' : 'Scan ulang' }}
            </button>
        </div>

        @if ($this->project === null)
            <p class="muted small" style="margin:0">Pilih project dulu di sidebar.</p>
        @elseif ($run === null)
            <p class="muted small" style="margin:0">Belum pernah di-scan. Scan berjalan di latar belakang; halaman ini mengikuti progresnya.</p>
        @else
            @php($percent = $run->percent())
            <div class="od-stack" style="gap:8px">
                <div class="od-row" style="justify-content:space-between;gap:12px">
                    <span class="small">
                        @switch($run->status)
                            @case(\App\Enums\ScanStatus::Queued)
                                Menunggu worker antrean…
                                @break
                            @case(\App\Enums\ScanStatus::Running)
                                Memeriksa <span class="mono">{{ $run->current_table ?? '…' }}</span>
                                ({{ $run->processed }}/{{ $run->total }})
                                @break
                            @case(\App\Enums\ScanStatus::Done)
                                Selesai · {{ $run->total }} tabel diperiksa
                                @break
                            @case(\App\Enums\ScanStatus::Failed)
                                Gagal
                                @break
                        @endswitch
                    </span>
                    <span class="mono small">{{ $percent }}%</span>
                </div>

                <div role="progressbar" aria-label="Progres scan" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"
                     style="height:10px;border-radius:999px;background:var(--adbm-panel-2);border:1px solid var(--adbm-border);overflow:hidden">
                    <div style="height:100%;width:{{ $percent }}%;background:{{ $run->status === \App\Enums\ScanStatus::Failed ? 'var(--adbm-danger)' : 'var(--adbm-accent)' }};transition:width 0.4s ease"></div>
                </div>
            </div>

            @if ($run->status === \App\Enums\ScanStatus::Failed)
                <div class="alert" role="alert">
                    <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                    <span>{{ $run->error }}</span>
                </div>
            @endif
        @endif
    </section>

    @if ($run !== null && $run->findings !== null && ($run->findings !== [] || $run->status === \App\Enums\ScanStatus::Done))
        <section class="card od-stack" style="gap:12px">
            <div class="od-row" style="justify-content:space-between;gap:12px">
                <h2 class="card-title">Temuan</h2>
                <span class="badge {{ $run->findings === [] ? 'ok' : 'warn' }}">{{ count($run->findings) }} tabel</span>
            </div>

            @if ($run->findings === [])
                <p class="muted small" style="margin:0">Tidak ada masalah ditemukan.</p>
            @else
                <ul class="od-stack" style="gap:8px;list-style:none;margin:0;padding:0">
                    @foreach ($run->findings as $finding)
                        <li class="od-stack" style="gap:2px" wire:key="finding-{{ $finding['table'] }}">
                            <span class="mono" style="font-weight:600">{{ $finding['table'] }}</span>
                            @foreach ($finding['issues'] as $issue)
                                <span class="muted small">{{ $issue }}</span>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
