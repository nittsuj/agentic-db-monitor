<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body>
    <x-icons />

    <div class="app">
        <livewire:shell.sidebar />

        <div class="scrim" id="scrim"></div>

        <main class="main" id="main">
            <div class="top">
                <div class="top-l">
                    <button type="button" class="btn icon menu-btn" id="menuBtn" aria-label="Buka menu" aria-expanded="false" aria-controls="side">
                        <svg class="od-icon"><use href="#i-menu" /></svg>
                    </button>

                    <div class="od-stack" style="gap:4px;min-width:0">
                        <h1 id="pageTitle" tabindex="-1">{{ $heading ?? $title ?? config('app.name') }}</h1>
                        @isset($subtitle)
                            <span class="mono muted small od-truncate" id="pageSub">{{ $subtitle }}</span>
                        @endisset
                    </div>
                </div>

                <div class="od-cluster" style="gap:12px" id="topActions">
                    {{ $actions ?? '' }}
                </div>
            </div>

            <div id="view">
                {{ $slot }}
            </div>
        </main>
    </div>

    <div class="toasts" id="toasts" role="status" aria-live="polite"></div>

    <livewire:projects.create-project-modal />
    <livewire:projects.project-manager />

    @fluxScripts
</body>
</html>
