<x-slot:subtitle>
    Diagnosis read-only · {{ $this->project?->name ?? '—' }}
</x-slot:subtitle>

<x-slot:actions>
    <a class="btn sm ghost" href="{{ route('chat.history') }}">Riwayat</a>
</x-slot:actions>


<div class="card" style="padding:0;overflow:hidden;height:calc(100vh - 11rem);min-height:28rem;display:flex;flex-direction:column" x-data="{ pending: '' }">
    @if ($this->project === null)
        {{-- "Choose a database first": opens on load and again when Kirim is pressed without a project. --}}
        <div x-data="{ open: true }"
             x-show="open"
             x-on:choose-project.window="open = true"
             x-on:keydown.escape.window="open = false"
             style="position:fixed;inset:0;z-index:60;display:grid;place-items:center;padding:16px;background:rgb(0 0 0 / 0.45)">
            <div class="card od-stack" role="dialog" aria-modal="true" aria-labelledby="chooseProjectTitle"
                 x-on:click.outside="open = false"
                 style="gap:14px;width:min(440px,100%);padding:20px;box-shadow:var(--adbm-shadow)">
                <div class="od-stack" style="gap:4px">
                    <h2 id="chooseProjectTitle" style="font-size:17px;font-weight:600;margin:0">Pilih database dulu</h2>
                    <p class="muted small" style="margin:0">Chat diagnostik bekerja pada satu project. Pilih database yang ingin ditanyakan.</p>
                </div>

                @if ($this->projects->isEmpty())
                    <p class="small" style="margin:0">Belum ada project. Tambahkan koneksi database terlebih dahulu.</p>
                    <flux:modal.trigger name="create-project-modal">
                        <button type="button" class="btn primary" x-on:click="open = false">
                            <svg class="od-icon"><use href="#i-plus" /></svg>
                            Tambah koneksi database
                        </button>
                    </flux:modal.trigger>
                @else
                    <ul class="od-stack" style="gap:8px;list-style:none;margin:0;padding:0">
                        @foreach ($this->projects as $project)
                            <li>
                                <a href="{{ route('switch-project', ['project' => $project->id, 'to' => 'chat']) }}"
                                   class="od-row"
                                   style="gap:10px;justify-content:space-between;padding:10px 12px;border:1px solid var(--adbm-border);border-radius:var(--adbm-radius-sm);color:var(--adbm-text);text-decoration:none">
                                    <span class="od-row" style="gap:10px;min-width:0">
                                        <svg class="od-icon"><use href="#i-db" /></svg>
                                        <span class="od-truncate">{{ $project->name }}</span>
                                    </span>
                                    <span class="badge mono">{{ $project->driver->value }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <button type="button" class="btn ghost sm" style="align-self:flex-end" x-on:click="open = false">Nanti saja</button>
            </div>
        </div>
    @endif

    <div class="od-stack" style="gap:10px;overflow-y:auto;flex:1 1 auto;padding:18px" aria-live="polite">
        @forelse ($messages as $message)
            <div class="od-row" style="justify-content:{{ $message['role'] === 'user' ? 'flex-end' : 'flex-start' }}">
                <div class="od-stack"
                     style="gap:4px;max-width:min(78%,680px);border-radius:12px;padding:10px 13px;background:{{ $message['role'] === 'user' ? 'var(--adbm-accent-soft)' : 'var(--adbm-panel-2)' }};color:var(--adbm-text)">
                    @if ($message['role'] === 'tool')
                        <span class="label" style="color:var(--adbm-accent)">Hasil tool</span>
                    @endif
                    @if ($message['role'] === 'assistant')
                        {{-- Model output is Markdown. Raw HTML in it is escaped and unsafe
                             links are dropped, so a reply cannot inject markup or scripts. --}}
                        <div class="md">{!! \App\Livewire\Chat\ChatWindow::renderMarkdown($message['content']) !!}</div>
                        @if (! empty($message['tools']))
                            <span class="od-row mono muted small" style="gap:6px" title="Alat yang dipakai agen untuk jawaban ini">
                                <svg class="od-icon" style="width:13px;height:13px"><use href="#i-tool" /></svg>
                                Alat: {{ implode(', ', $message['tools']) }}
                            </span>
                        @endif
                    @else
                        <span style="font-size:14px;line-height:1.55;white-space:pre-wrap">{{ $message['content'] }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="od-stack" style="gap:8px;align-items:center;justify-content:center;height:100%;text-align:center">
                <svg class="od-icon" style="width:30px;height:30px;color:var(--adbm-muted)"><use href="#i-chat" /></svg>
                <p class="muted small">Tanya kondisi database, misalnya “query apa yang paling lambat?” atau “kenapa koneksi menumpuk?”</p>
            </div>
        @endforelse

        {{-- Shown from the moment Kirim is pressed: wire:stream needs this element
             in the DOM before the request starts, or the streamed tokens have
             nowhere to land and the reply only appears when the request ends. --}}
        <div wire:loading.flex wire:target="send" class="od-stack" style="gap:8px">
            {{-- The user's own message, echoed while the server works on the reply. --}}
            <div class="od-row" style="justify-content:flex-end" x-show="pending !== ''">
                <div style="max-width:min(78%,680px);border-radius:12px;padding:10px 13px;background:var(--adbm-accent-soft);color:var(--adbm-text)">
                    <span x-text="pending" style="font-size:14px;line-height:1.55;white-space:pre-wrap"></span>
                </div>
            </div>

            <div class="od-row" style="justify-content:flex-start">
                <div class="od-stack"
                     style="gap:4px;max-width:min(78%,680px);border-radius:12px;padding:10px 13px;background:var(--adbm-panel-2);color:var(--adbm-text)">
                    <div class="md" wire:stream="assistantContent">{!! \App\Livewire\Chat\ChatWindow::renderMarkdown($assistantContent) !!}</div>
                </div>
            </div>

            <div class="od-row" style="justify-content:flex-start">
                <span class="badge info" role="status">Memeriksa database…</span>
            </div>
        </div>

        @if ($errorMessage !== null)
            <div class="alert" role="alert">
                <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                <span>{{ $errorMessage }}</span>
            </div>
        @endif
    </div>

    <form wire:submit="send" x-on:submit="pending = ($wire.input || '').trim(); $nextTick(() => { $el.querySelector('input').value = '' })" class="od-row" style="gap:10px;border-top:1px solid var(--adbm-border);padding:12px 14px">
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

    @if (count($this->aiOptions) > 0)
        <div class="od-row" style="gap:8px;padding:0 14px 12px;margin-top:-4px">
            <label class="label" for="chat-ai-model" style="flex:none">Model AI</label>
            <select id="chat-ai-model" class="input" style="max-width:340px;padding-top:4px;padding-bottom:4px;font-size:13px"
                    wire:model.live="aiSettingId"
                    wire:loading.attr="disabled" wire:target="send">
                @foreach ($this->aiOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <a href="{{ route('settings.ai') }}" class="muted small" style="flex:none">Kelola provider</a>
        </div>
    @endif
</div>
