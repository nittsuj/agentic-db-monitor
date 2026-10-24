<div>
    <flux:modal name="create-project-modal" class="md:w-[34rem]">
        <div class="od-stack" style="gap:16px">
            <div class="od-stack" style="gap:4px">
                <h2 class="card-title">Add Database Profile</h2>
                <p class="muted small">Hubungkan database MySQL atau PostgreSQL yang ingin dipantau.</p>
            </div>

            @if ($errorMessage)
                <div class="alert">
                    <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                    <div>
                        <strong>Pemeriksaan koneksi gagal</strong>
                        <span class="small">{{ $errorMessage }}</span>
                    </div>
                </div>
            @endif

            @if (count($warnings) > 0)
                <div class="alert" style="border-color:var(--adbm-warning);background-color:var(--adbm-warning-soft)">
                    <svg class="od-icon" style="color:var(--adbm-warning)"><use href="#i-info" /></svg>
                    <div class="od-stack" style="gap:4px">
                        <strong>Rekomendasi hak akses</strong>
                        <ul style="list-style:disc;padding-left:18px;margin:0" class="small">
                            @foreach ($warnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form wire:submit="save" class="od-stack" style="gap:12px">
                <div class="od-stack" style="gap:6px">
                    <label class="label" for="cp-name">Nama workspace / project</label>
                    <input id="cp-name" class="input" type="text" wire:model="name" placeholder="mis. Produksi MySQL" required>
                </div>

                <div class="od-row" style="gap:12px;align-items:flex-end">
                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-driver">Driver database</label>
                        <select id="cp-driver" class="input" wire:model.live="driver">
                            <option value="mysql">MySQL</option>
                            <option value="pgsql">PostgreSQL</option>
                        </select>
                    </div>

                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-port">Port</label>
                        <input id="cp-port" class="input" type="number" wire:model="port" min="1" max="65535" required>
                    </div>
                </div>

                <div class="od-row" style="gap:12px;align-items:flex-end">
                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-host">Host</label>
                        <input id="cp-host" class="input" type="text" wire:model.blur="host" placeholder="127.0.0.1" required>
                    </div>

                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-database">Nama database</label>
                        <input id="cp-database" class="input" type="text" wire:model="database" placeholder="mydb" required>
                    </div>
                </div>

                <div class="od-row" style="gap:12px;align-items:flex-end">
                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-username">Username</label>
                        <input id="cp-username" class="input" type="text" wire:model="username" placeholder="root" required>
                    </div>

                    <div class="od-stack" style="gap:6px;flex:1 1 0">
                        <label class="label" for="cp-password">Password</label>
                        <input id="cp-password" class="input" type="password" wire:model="password" placeholder="••••••••">
                    </div>
                </div>

                @include('livewire.projects.partials.ssl-fields', ['prefix' => 'cp'])

                <div class="od-row" style="gap:8px;justify-content:flex-end;border-top:1px solid var(--adbm-border);padding-top:14px">
                    <flux:modal.close>
                        <button type="button" class="btn ghost">Batal</button>
                    </flux:modal.close>

                    <button type="submit" class="btn primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Uji hak akses &amp; simpan</span>
                        <span wire:loading>Menguji hak akses…</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
