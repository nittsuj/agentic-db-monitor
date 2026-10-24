{{-- SSL/TLS fields shared by the create and edit forms (ConnectionSslFields). --}}
<div class="od-stack" style="gap:6px">
    <label class="label" for="{{ $prefix }}-ssl-mode">Keamanan koneksi (SSL/TLS)</label>
    <select id="{{ $prefix }}-ssl-mode" class="input" wire:model.live="sslMode">
        @foreach (\App\Enums\SslMode::cases() as $mode)
            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
        @endforeach
    </select>
    @error('sslMode') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
</div>

@if (\App\Enums\SslMode::tryFrom($sslMode)?->encrypts())
    <div class="od-stack" style="gap:6px">
        <label class="label" for="{{ $prefix }}-ssl-ca">Sertifikat CA (PEM, opsional)</label>
        <textarea id="{{ $prefix }}-ssl-ca" class="input" rows="4" wire:model="sslCa"
            style="font-family:var(--font-mono, monospace);font-size:12px"
            placeholder="-----BEGIN CERTIFICATE-----"></textarea>
        <span class="muted small">Kosongkan untuk memakai CA sistem (Supabase, Neon, DigitalOcean). RDS/Cloud SQL: tempel bundle CA dari penyedia.</span>
        @error('sslCa') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
    </div>
@endif
