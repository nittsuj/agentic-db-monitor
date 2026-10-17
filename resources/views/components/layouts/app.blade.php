<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.nav>
            <flux:sidebar.item icon="chart-bar" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                Dashboard
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.nav class="mt-6">
            <div class="px-3 py-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">
                Workspaces
            </div>
            @foreach (\App\Models\Project::all() as $p)
                <flux:sidebar.item
                    :href="route('switch-project', $p)"
                    :current="session('active_project_id') === $p->id"
                    icon="circle-stack">
                    {{ $p->name }}
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
