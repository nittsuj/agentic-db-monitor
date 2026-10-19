<aside class="side" id="side" aria-label="Navigasi utama">
    <a class="brand od-row" href="{{ route('dashboard') }}">
        <svg class="od-icon"><use href="#i-db" /></svg>
        <span>Agentic DB Monitor</span>
    </a>

    <div class="od-stack" style="gap:8px">
        <label class="label" for="proj">Project aktif</label>

        <select id="proj" class="input" wire:model.live="projectId">
            <option value="">— pilih project —</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}">{{ $project->name }}</option>
            @endforeach
        </select>

        <div class="od-row" style="gap:8px">
            <flux:modal.trigger name="create-project-modal">
                <button type="button" class="btn sm od-fill">
                    <svg class="od-icon"><use href="#i-plus" /></svg>
                    Project
                </button>
            </flux:modal.trigger>

            <flux:modal.trigger name="project-manager">
                <button type="button" class="btn sm od-fill">
                    <svg class="od-icon"><use href="#i-edit" /></svg>
                    Kelola
                </button>
            </flux:modal.trigger>
        </div>
    </div>

    <nav aria-label="Halaman">
        <ul class="nav">
            <li>
                <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                    <svg class="od-icon"><use href="#i-grid" /></svg>
                    Dashboard
                </a>
            </li>
            <li>
                <a href="{{ route('chat') }}" @if(request()->routeIs('chat')) aria-current="page" @endif>
                    <svg class="od-icon"><use href="#i-chat" /></svg>
                    Chat diagnostik
                </a>
            </li>
            <li>
                <a href="{{ route('approvals') }}" @if(request()->routeIs('approvals')) aria-current="page" @endif>
                    <svg class="od-icon"><use href="#i-shield" /></svg>
                    Persetujuan
                    @if ($pendingCount > 0)
                        <span class="count">{{ $pendingCount }}</span>
                    @endif
                </a>
            </li>
            <li>
                <a href="{{ route('thresholds') }}" @if(request()->routeIs('thresholds')) aria-current="page" @endif>
                    <svg class="od-icon"><use href="#i-gauge" /></svg>
                    Threshold
                </a>
            </li>
            <li>
                <a href="{{ route('settings.ai') }}" @if(request()->routeIs('settings.ai')) aria-current="page" @endif>
                    <svg class="od-icon"><use href="#i-gear" /></svg>
                    Pengaturan AI
                </a>
            </li>
        </ul>
    </nav>

    <a class="provider od-stack" href="{{ route('settings.ai') }}" style="gap:4px">
        <span class="label">Provider AI</span>
        <span class="mono small">{{ $provider['model'] }}</span>
        <span class="muted small od-truncate">{{ $provider['base_url'] }}</span>
        <span class="badge {{ $hasKey ? 'ok' : 'warn' }}">
            <svg class="od-icon" style="width:13px;height:13px"><use href="#i-plug" /></svg>
            {{ $hasKey ? 'API key tersimpan' : 'API key belum ada' }}
        </span>
    </a>

    <div class="od-stack" style="gap:6px">
        <button type="button" class="btn ghost sm" wire:click="resetDemoData"
                wire:confirm="Pulihkan data demo? Data yang kamu buat sendiri tidak akan dihapus.">
            <svg class="od-icon"><use href="#i-broom" /></svg>
            Pulihkan data demo
        </button>

        <button type="button" class="btn ghost sm" data-theme-toggle>
            <svg class="od-icon"><use href="#i-moon" /></svg>
            Ganti tema
        </button>

        @if ($notice)
            <span class="badge ok">{{ $notice }}</span>
        @endif
    </div>
</aside>
