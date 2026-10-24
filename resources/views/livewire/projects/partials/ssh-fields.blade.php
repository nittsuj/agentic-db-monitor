{{-- SSH tunnel fields shared by the create and edit forms (ConnectionSshFields). --}}
<div class="od-stack" style="gap:8px">
    <label class="od-row small" style="gap:8px;align-items:center;cursor:pointer">
        <input type="checkbox" wire:model.live="sshEnabled">
        <span class="label" style="margin:0">Hubungkan lewat SSH tunnel (bastion)</span>
    </label>

    @if ($sshEnabled)
        <span class="muted small">Host database di atas di-resolve dari bastion. Untuk database di server bastion itu sendiri, isi host database dengan <code>127.0.0.1</code>.</span>

        <div class="od-row" style="gap:12px;align-items:flex-end">
            <div class="od-stack" style="gap:6px;flex:2 1 0">
                <label class="label" for="{{ $prefix }}-ssh-host">SSH host</label>
                <input id="{{ $prefix }}-ssh-host" class="input" type="text" wire:model="sshHost" placeholder="bastion.example.com">
                @error('sshHost') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
            </div>
            <div class="od-stack" style="gap:6px;flex:1 1 0">
                <label class="label" for="{{ $prefix }}-ssh-port">SSH port</label>
                <input id="{{ $prefix }}-ssh-port" class="input" type="number" wire:model="sshPort" min="1" max="65535">
                @error('sshPort') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="od-row" style="gap:12px;align-items:flex-end">
            <div class="od-stack" style="gap:6px;flex:1 1 0">
                <label class="label" for="{{ $prefix }}-ssh-user">SSH username</label>
                <input id="{{ $prefix }}-ssh-user" class="input" type="text" wire:model="sshUsername" placeholder="tunnel">
                @error('sshUsername') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
            </div>
            <div class="od-stack" style="gap:6px;flex:1 1 0">
                <label class="label" for="{{ $prefix }}-ssh-auth">Autentikasi</label>
                <select id="{{ $prefix }}-ssh-auth" class="input" wire:model.live="sshAuth">
                    <option value="key">Private key</option>
                    <option value="password">Password</option>
                </select>
            </div>
        </div>

        @if ($sshAuth === 'key')
            <div class="od-stack" style="gap:6px">
                <label class="label" for="{{ $prefix }}-ssh-key">Private key</label>
                <textarea id="{{ $prefix }}-ssh-key" class="input" rows="4" wire:model="sshPrivateKey"
                    style="font-family:var(--font-mono, monospace);font-size:12px"
                    placeholder="{{ $sshHasStoredKey ? 'Tersimpan (kosongkan untuk mempertahankan)' : '-----BEGIN OPENSSH PRIVATE KEY-----' }}"></textarea>
                @error('sshPrivateKey') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
            </div>
            <div class="od-stack" style="gap:6px">
                <label class="label" for="{{ $prefix }}-ssh-passphrase">Passphrase key (opsional)</label>
                <input id="{{ $prefix }}-ssh-passphrase" class="input" type="password" wire:model="sshPassphrase"
                    placeholder="{{ $sshHasStoredKey ? '•••••••• (kosongkan untuk mempertahankan)' : '' }}">
            </div>
        @else
            <div class="od-stack" style="gap:6px">
                <label class="label" for="{{ $prefix }}-ssh-password">SSH password</label>
                <input id="{{ $prefix }}-ssh-password" class="input" type="password" wire:model="sshPassword"
                    placeholder="{{ $sshHasStoredPassword ? '•••••••• (kosongkan untuk mempertahankan)' : '' }}">
                @error('sshPassword') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
            </div>
        @endif

        <span class="muted small">Gunakan key khusus dengan akses port-forward saja. Host key bastion dicatat saat koneksi pertama; perubahan berikutnya akan ditolak.</span>
    @endif
</div>
