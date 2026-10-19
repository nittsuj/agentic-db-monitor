<x-slot:subtitle>
    Diagnosis read-only · {{ $this->project?->name ?? '—' }}
</x-slot:subtitle>

<div class="card" style="padding:0;overflow:hidden;height:calc(100vh - 11rem);min-height:28rem;display:flex;flex-direction:column">
    <div class="od-stack" style="gap:10px;overflow-y:auto;flex:1 1 auto;padding:18px" aria-live="polite">
        @forelse ($messages as $message)
            <div class="od-row" style="justify-content:{{ $message['role'] === 'user' ? 'flex-end' : 'flex-start' }}">
                <div class="od-stack"
                     style="gap:4px;max-width:min(78%,680px);border-radius:12px;padding:10px 13px;background:{{ $message['role'] === 'user' ? 'var(--adbm-accent-soft)' : 'var(--adbm-panel-2)' }};color:var(--adbm-text)">
                    @if ($message['role'] === 'tool')
                        <span class="label" style="color:var(--adbm-accent)">Hasil tool</span>
                    @endif
                    <span style="font-size:14px;line-height:1.55;white-space:pre-wrap">{{ $message['content'] }}</span>
                </div>
            </div>
        @empty
            <div class="od-stack" style="gap:8px;align-items:center;justify-content:center;height:100%;text-align:center">
                <svg class="od-icon" style="width:30px;height:30px;color:var(--adbm-muted)"><use href="#i-chat" /></svg>
                <p class="muted small">Tanya kondisi database, misalnya “query apa yang paling lambat?” atau “kenapa koneksi menumpuk?”</p>
            </div>
        @endforelse

        @if ($assistantContent !== '')
            <div class="od-row" style="justify-content:flex-start">
                <div class="od-stack"
                     style="gap:4px;max-width:min(78%,680px);border-radius:12px;padding:10px 13px;background:var(--adbm-panel-2);color:var(--adbm-text)">
                    <span wire:stream="assistantContent" style="font-size:14px;line-height:1.55;white-space:pre-wrap">{{ $assistantContent }}</span>
                </div>
            </div>
        @endif

        @if ($isSending)
            <div class="od-row" style="justify-content:flex-start">
                <span class="badge info" role="status">Memeriksa database…</span>
            </div>
        @endif

        @if ($errorMessage !== null)
            <div class="alert" role="alert">
                <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif
    </div>

    <form wire:submit="send" class="od-row" style="gap:10px;border-top:1px solid var(--adbm-border);padding:12px 14px">
        <input type="text" wire:model="input"
               class="input"
               placeholder="Tanya tentang database…"
               aria-label="Pesan"
               @disabled($isSending)>

        <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="send" @disabled($isSending)>
            <span wire:loading.remove wire:target="send">
                <svg class="od-icon"><use href="#i-send" /></svg>
                Kirim
            </span>
            <span wire:loading wire:target="send">Memproses…</span>
        </button>
    </form>
</div>
