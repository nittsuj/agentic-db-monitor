<x-slot:subtitle>
    Diagnosis read-only · {{ $this->project?->name ?? '—' }}
</x-slot:subtitle>

<x-slot:actions>
    <a class="btn sm ghost" href="{{ route('chat.history') }}">Riwayat</a>
</x-slot:actions>


<div class="chat-hub" x-data="{ showCode: true }" :class="{ 'has-code': showCode && {{ count($this->codeBlocks) > 0 ? 'true' : 'false' }} }">
<aside class="card chat-sessions">
    {{-- Its own component: it talks to this one only through session-selected and message-sent. --}}
    <livewire:chat.session-list :active-session-id="$chatSessionId" key="chat-session-list" />
</aside>

<div class="card" style="padding:0;overflow:hidden;min-height:0;display:flex;flex-direction:column" x-data="{ pending: '' }">
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

@if (count($this->codeBlocks) > 0)
    {{-- Code from the latest reply, highlighted by resources/js/highlight.js. The
         key changes with every new reply, so the tabs start over at the first block. --}}
    <aside class="card chat-code" x-show="showCode" aria-label="Kode dari jawaban terakhir"
           wire:key="code-panel-{{ count($messages) }}"
           x-data="{ tab: 0, copied: false }">
        <div class="od-row" style="gap:8px;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--adbm-border)">
            <span class="od-row" style="gap:8px;font-weight:600;font-size:13.5px">
                <svg class="od-icon"><use href="#i-tool" /></svg>
                Kode
            </span>
            <button type="button" class="btn ghost sm" x-on:click="showCode = false" aria-label="Tutup panel kode">
                <svg class="od-icon"><use href="#i-x" /></svg>
            </button>
        </div>

        @if (count($this->codeBlocks) > 1)
            <div class="od-cluster" role="tablist" style="gap:6px;padding:8px 12px 0">
                @foreach ($this->codeBlocks as $index => $block)
                    <button type="button" role="tab" class="btn sm"
                            :class="tab === {{ $index }} ? 'primary' : 'ghost'"
                            :aria-selected="tab === {{ $index }}"
                            x-on:click="tab = {{ $index }}; copied = false">
                        {{ $index + 1 }} · <span class="mono">{{ $block['language'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        @foreach ($this->codeBlocks as $index => $block)
            @php($previewable = $block['language'] === 'html')
            <div class="od-stack md" style="gap:8px;padding:10px 12px;min-height:0;flex:1 1 auto" x-show="tab === {{ $index }}" role="tabpanel"
                 x-data="{ view: 'code' }">
                <div class="od-row" style="gap:6px;justify-content:space-between">
                    <span class="badge mono">{{ $block['language'] }}</span>
                    <span class="od-row" style="gap:6px">
                        @if ($previewable)
                            <button type="button" class="btn sm" :class="view === 'code' ? 'primary' : 'ghost'" x-on:click="view = 'code'">Kode</button>
                            <button type="button" class="btn sm" :class="view === 'preview' ? 'primary' : 'ghost'" x-on:click="view = 'preview'">Pratinjau</button>
                        @endif
                        <button type="button" class="btn ghost sm"
                                x-on:click="navigator.clipboard.writeText($refs.code{{ $index }}.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                            <span x-text="copied ? 'Disalin' : 'Salin'">Salin</span>
                        </button>
                    </span>
                </div>
                <pre x-show="view === 'code'" style="margin:0;flex:1 1 auto;overflow:auto"><code x-ref="code{{ $index }}" class="language-{{ $block['language'] }}">{{ $block['code'] }}</code></pre>
                @if ($previewable)
                    {{-- An empty sandbox: no scripts, forms, popups, or access to this
                         app's origin, so AI-written HTML can only be looked at. --}}
                    <iframe x-show="view === 'preview'" x-cloak sandbox="" referrerpolicy="no-referrer" loading="lazy"
                            title="Pratinjau HTML" srcdoc="{{ $block['code'] }}"
                            style="flex:1 1 auto;width:100%;min-height:16rem;border:1px solid var(--adbm-border);border-radius:var(--adbm-radius-sm);background:#fff"></iframe>
                @endif
            </div>
        @endforeach
    </aside>
@endif

@if (count($this->codeBlocks) > 0)
    <button type="button" class="btn sm chat-code-reopen" x-show="!showCode" x-on:click="showCode = true">
        <svg class="od-icon"><use href="#i-tool" /></svg>
        Tampilkan kode
    </button>
@endif
</div>
