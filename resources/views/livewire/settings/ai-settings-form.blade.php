<x-slot:subtitle>
    Provider, model, dan API key untuk asisten
</x-slot:subtitle>

<div class="od-stack" style="gap:18px">
    <section class="card od-stack" style="gap:14px">
        <div class="od-stack" style="gap:4px">
            <h2 class="card-title">Pengaturan AI</h2>
            <p class="muted small">
                Default aplikasi adalah LLM gateway ITS
                (<span class="mono">https://senopati.its.ac.id/llm-gateway/v1</span>, model
                <span class="mono">qwen3:14b</span>). API key disimpan terenkripsi di database lokal.
            </p>
        </div>

        @if ($test_result)
            <div class="alert" style="border-color:var(--adbm-accent);background-color:var(--adbm-accent-soft)">
                <svg class="od-icon" style="color:var(--adbm-accent)"><use href="#i-check" /></svg>
                <span>{{ $test_result }}</span>
            </div>
        @endif

        @if ($test_error)
            <div class="alert">
                <svg class="od-icon" style="color:var(--adbm-danger)"><use href="#i-alert" /></svg>
                <span>{{ $test_error }}</span>
            </div>
        @endif

        <form wire:submit="save" class="od-stack" style="gap:12px">
            <div class="od-row" style="gap:12px;align-items:flex-end">
                <div class="od-stack" style="gap:6px;flex:1 1 0">
                    <label class="label" for="ai-provider">Provider</label>
                    <select id="ai-provider" class="input" wire:model.live="provider">
                        @foreach (\App\Services\Ai\AiProviderConfig::providers() as $provider)
                            <option value="{{ $provider }}">{{ $provider }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="od-stack" style="gap:6px;flex:1 1 0">
                    <label class="label" for="ai-model">Model</label>
                    <input id="ai-model" class="input" type="text" wire:model="model" required>
                </div>
            </div>

            <div class="od-stack" style="gap:6px">
                <label class="label" for="ai-base-url">Base URL</label>
                <input id="ai-base-url" class="input mono" type="url" wire:model="base_url" required>
            </div>

            <div class="od-stack" style="gap:6px">
                <label class="label" for="ai-key">API key</label>
                <input id="ai-key" class="input" type="password" wire:model="api_key_input"
                       placeholder="{{ $has_existing_key ? 'Key tersimpan, biarkan kosong untuk mempertahankan' : 'Masukkan API key' }}">
            </div>

            @error('model')
                <p class="muted small" style="color:var(--adbm-danger)">{{ $message }}</p>
            @enderror

            @error('base_url')
                <p class="muted small" style="color:var(--adbm-danger)">{{ $message }}</p>
            @enderror

            @error('api_key_input')
                <p class="muted small" style="color:var(--adbm-danger)">{{ $message }}</p>
            @enderror

            <div class="od-row" style="gap:8px;justify-content:flex-end;border-top:1px solid var(--adbm-border);padding-top:14px">
                <button type="button" class="btn" wire:click="testConnection" wire:loading.attr="disabled">
                    <svg class="od-icon"><use href="#i-plug" /></svg>
                    <span wire:loading.remove>Tes koneksi</span>
                    <span wire:loading>Menguji…</span>
                </button>

                <button type="submit" class="btn primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan pengaturan</span>
                    <span wire:loading>Menyimpan…</span>
                </button>
            </div>
        </form>
    </section>

    <section class="card od-stack" style="gap:8px">
        <h2 class="card-title">Tentang provider</h2>
        <ul class="od-stack" style="gap:6px;list-style:none;padding:0;margin:0" role="list">
            <li class="muted small">
                <span class="mono">openai_compatible</span> — endpoint OpenAI Chat Completions, dipakai untuk LLM gateway ITS.
            </li>
            <li class="muted small">
                <span class="mono">ollama</span> — model lokal, tanpa API key.
            </li>
            <li class="muted small">
                <span class="mono">openai</span> — API resmi OpenAI.
            </li>
            <li class="muted small">
                Jika provider tidak mendukung function calling, chat otomatis turun ke mode tanpa tool dan
                memberi peringatan di kolom percakapan.
            </li>
        </ul>
    </section>
</div>
