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
            <flux:sidebar.item icon="chat-bubble-left-right" :href="route('chat')" :current="request()->routeIs('chat')">
                Chat
            </flux:sidebar.item>
            <flux:sidebar.item icon="shield-check" :href="route('approvals')" :current="request()->routeIs('approvals')">
                Approvals
            </flux:sidebar.item>
            <flux:sidebar.item icon="cog-6-tooth" :href="route('settings.ai')" :current="request()->routeIs('settings.ai')">
                AI Settings
            </flux:sidebar.item>
            <flux:sidebar.item icon="adjustments-horizontal" :href="route('thresholds')" :current="request()->routeIs('thresholds')">
                Thresholds
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.nav class="mt-6">
            <div class="flex items-center justify-between px-3 py-1">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">
                    Workspaces
                </span>
                <flux:modal.trigger name="create-project-modal">
                    <flux:button size="xs" variant="ghost" icon="plus" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white" title="Add Database Profile" />
                </flux:modal.trigger>
            </div>

            @forelse (\App\Models\Project::orderBy('name')->get() as $p)
                <flux:sidebar.item
                    :href="route('switch-project', $p)"
                    :current="session('active_project_id') === $p->id"
                    icon="circle-stack">
                    {{ $p->name }}
                </flux:sidebar.item>
            @empty
                <div class="px-3 py-2 text-xs text-zinc-400">
                    No databases connected
                </div>
            @endforelse

            <div class="px-2 pt-2">
                <flux:modal.trigger name="create-project-modal">
                    <flux:button size="sm" variant="outline" class="w-full justify-start" icon="plus">
                        Add Database
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:main>
        {{ $slot }}
    </flux:main>

    <livewire:projects.create-project-modal />

    @fluxScripts
</body>
</html>
