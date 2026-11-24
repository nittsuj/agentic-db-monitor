<nav class="od-stack" style="gap:10px;min-height:0;height:100%" aria-label="Daftar chat">
    <button type="button" class="btn primary sm" wire:click="newChat" wire:loading.attr="disabled" wire:target="newChat"
            @disabled($projectId === null)>
        <svg class="od-icon"><use href="#i-plus" /></svg>
        Chat baru
    </button>

    <ul class="od-stack" style="gap:4px;list-style:none;margin:0;padding:0;overflow-y:auto;flex:1 1 auto">
        @forelse ($this->sessions as $session)
            @php($active = $session->id === $activeSessionId)
            <li wire:key="session-{{ $session->id }}">
                <button type="button"
                        wire:click="select('{{ $session->id }}')"
                        @if ($active) aria-current="true" @endif
                        class="od-stack"
                        style="gap:2px;width:100%;text-align:left;cursor:pointer;padding:8px 10px;border-radius:var(--adbm-radius-sm);border:1px solid {{ $active ? 'var(--adbm-accent)' : 'transparent' }};background:{{ $active ? 'var(--adbm-accent-soft)' : 'transparent' }};color:var(--adbm-text)">
                    <span class="od-truncate" style="font-size:13.5px;font-weight:{{ $active ? 600 : 500 }}">
                        {{ $session->title ?? 'Chat baru' }}
                    </span>
                    <span class="muted small">
                        {{ $session->messages_count }} pesan · {{ $session->updated_at?->diffForHumans() }}
                    </span>
                </button>
            </li>
        @empty
            <li class="muted small" style="padding:8px 10px">
                {{ $projectId === null ? 'Pilih database dulu.' : 'Belum ada chat.' }}
            </li>
        @endforelse
    </ul>
</nav>
