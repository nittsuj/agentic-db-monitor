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

    {{-- Pages for signed-out visitors: no sidebar, one centred card. --}}
    <main style="min-height:100vh;display:grid;place-items:center;padding:24px">
        <div class="od-stack" style="gap:16px;width:min(100%,380px)">
            <div class="brand od-row">
                <svg class="od-icon"><use href="#i-db" /></svg>
                <span>Agentic DB Monitor</span>
            </div>

            <section class="card od-stack" style="gap:16px">
                {{ $slot }}
            </section>
        </div>
    </main>
</body>
</html>
