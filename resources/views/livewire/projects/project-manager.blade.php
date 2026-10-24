<div>
    <flux:modal name="project-manager" class="md:w-[42rem]">
        <div class="space-y-5">
            <div>
                <h2 class="card-title">Kelola project</h2>
                <p class="muted small">Ubah koneksi target atau hapus project. Menghapus project ikut menghapus chat, metrik, threshold, dan usulan yang tertunda; audit log tetap tersimpan.</p>
            </div>

            @if ($notice)
                <div class="alert" style="border-color: var(--adbm-accent); background-color: var(--adbm-accent-soft)">
                    <svg class="od-icon" style="color: var(--adbm-accent)"><use href="#i-check" /></svg>
                    <span>{{ $notice }}</span>
                </div>
            @endif

            @if ($errorMessage)
                <div class="alert">
                    <svg class="od-icon" style="color: var(--adbm-danger)"><use href="#i-alert" /></svg>
                    <span>{{ $errorMessage }}</span>
                </div>
            @endif

            <ul class="nav" style="gap:4px">
                @forelse ($projects as $project)
                    <li>
                        <button type="button" class="btn ghost od-fill" style="justify-content:flex-start"
                            wire:click="edit('{{ $project->id }}')">
                            <svg class="od-icon"><use href="#i-db" /></svg>
                            <span class="od-truncate">{{ $project->name }}</span>
                            <span class="badge">{{ $project->driver->value }}</span>
                        </button>
                    </li>
                @empty
                    <li class="muted small">Belum ada project.</li>
                @endforelse
            </ul>

            @if ($projectId !== null)
                <div class="card" style="box-shadow:none">
                    <form wire:submit="save" class="od-stack" style="gap:12px">
                        <label class="label" for="pm-name">Nama workspace</label>
                        <input id="pm-name" class="input" type="text" wire:model="name" required>

                        <div class="od-row" style="gap:12px;align-items:flex-end">
                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-driver">Driver</label>
                                <select id="pm-driver" class="input" wire:model.live="driver">
                                    <option value="mysql">MySQL</option>
                                    <option value="pgsql">PostgreSQL</option>
                                </select>
                            </div>

                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-port">Port</label>
                                <input id="pm-port" class="input" type="number" wire:model="port" min="1" max="65535" required>
                            </div>
                        </div>

                        <div class="od-row" style="gap:12px;align-items:flex-end">
                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-host">Host</label>
                                <input id="pm-host" class="input" type="text" wire:model="host" required>
                            </div>

                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-database">Database</label>
                                <input id="pm-database" class="input" type="text" wire:model="database" required>
                            </div>
                        </div>

                        <div class="od-row" style="gap:12px;align-items:flex-end">
                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-username">Username</label>
                                <input id="pm-username" class="input" type="text" wire:model="username" required>
                            </div>

                            <div class="od-stack" style="gap:6px;flex:1 1 0">
                                <label class="label" for="pm-password">Password</label>
                                <input id="pm-password" class="input" type="password" wire:model="password"
                                    placeholder="•••••••• (kosongkan untuk mempertahankan)">
                            </div>
                        </div>

                        @include('livewire.projects.partials.ssl-fields', ['prefix' => 'pm'])

                        @include('livewire.projects.partials.preflight-results')

                        <div class="od-row" style="gap:8px;justify-content:flex-end;border-top:1px solid var(--adbm-border);padding-top:14px">
                            <button type="button" class="btn ghost" wire:click="cancel">Batal</button>
                            <button type="button" class="btn ghost" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                                <span wire:loading.remove wire:target="testConnection">Tes koneksi</span>
                                <span wire:loading wire:target="testConnection">Menguji…</span>
                            </button>
                            <button type="submit" class="btn primary">Simpan perubahan</button>
                        </div>
                    </form>

                    <div class="od-row" style="gap:8px;justify-content:flex-end;border-top:1px solid var(--adbm-border);margin-top:14px;padding-top:14px">
                        @if ($confirmingDelete)
                            <span class="muted small">Hapus project ini beserta datanya?</span>
                            <button type="button" class="btn ghost" wire:click="$set('confirmingDelete', false)">Batal</button>
                            <button type="button" class="btn danger" wire:click="delete">Ya, hapus</button>
                        @else
                            <button type="button" class="btn danger" wire:click="$set('confirmingDelete', true)">
                                <svg class="od-icon"><use href="#i-trash" /></svg>
                                Hapus project
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
