{{-- Pre-flight result shared by the create and edit forms (ConnectionPreflight). --}}
@if ($privilegeLevel === 'admin')
    <div class="alert" style="border-color:var(--adbm-danger);background-color:var(--adbm-danger-soft, var(--adbm-warning-soft))">
        <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
        <div class="od-stack" style="gap:6px;min-width:0">
            <strong>Terhubung sebagai superuser / admin{{ $checkedUser ? ' ('.$checkedUser.')' : '' }}</strong>
            <ul style="list-style:disc;padding-left:18px;margin:0" class="small">
                @foreach ($privilegeNotes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
            <span class="small">Diagnostik hanya membaca data. Untuk keamanan, gunakan user khusus read-only seperti <code>adbm_ro</code>:</span>
            <pre class="small" style="margin:0;padding:8px;overflow-x:auto;white-space:pre;background:var(--adbm-surface-2, rgba(0,0,0,.04));border-radius:6px">{{ $this->readOnlyRoleSql() }}</pre>
            <span class="muted small">Catatan: dengan user read-only, aksi tulis yang Anda setujui di Approvals akan ditolak database.</span>
        </div>
    </div>
@elseif ($privilegeLevel === 'write')
    <div class="alert" style="border-color:var(--adbm-warning);background-color:var(--adbm-warning-soft)">
        <svg class="od-icon" style="color:var(--adbm-warning)"><use href="#i-info" /></svg>
        <div class="od-stack" style="gap:4px">
            <strong>User ini bisa menulis data{{ $checkedUser ? ' ('.$checkedUser.')' : '' }}</strong>
            <ul style="list-style:disc;padding-left:18px;margin:0" class="small">
                @foreach ($privilegeNotes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
            <span class="muted small">Perubahan tetap hanya berjalan setelah Anda menyetujuinya di Approvals.</span>
        </div>
    </div>
@endif

@if (count($warnings) > 0)
    <div class="alert" style="border-color:var(--adbm-warning);background-color:var(--adbm-warning-soft)">
        <svg class="od-icon" style="color:var(--adbm-warning)"><use href="#i-info" /></svg>
        <div class="od-stack" style="gap:4px">
            <strong>Fitur pemantauan yang belum tersedia</strong>
            <ul style="list-style:disc;padding-left:18px;margin:0" class="small">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@if (count($passedChecks) > 0 && $privilegeLevel !== null)
    <details class="small">
        <summary class="muted">{{ count($passedChecks) }} pemeriksaan lulus @if ($privilegeLevel === 'read_only') · user read-only @endif</summary>
        <ul style="list-style:disc;padding-left:18px;margin:4px 0 0">
            @foreach ($passedChecks as $check)
                <li>{{ $check }}</li>
            @endforeach
        </ul>
    </details>
@endif
