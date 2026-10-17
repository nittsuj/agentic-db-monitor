<div>
    <flux:card>
        <flux:heading size="lg">Pending Approvals</flux:heading>

        @forelse ($actions as $action)
            <div class="mt-4 rounded border p-3 space-y-2">
                <div class="text-xs text-zinc-500">{{ $action->category->value }} · {{ $action->engine }}</div>
                <pre class="rounded bg-zinc-900 text-green-300 p-2 text-sm overflow-x-auto">{{ $action->command }}</pre>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="primary" wire:click="approve('{{ $action->id }}')">Approve</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="deny('{{ $action->id }}')">Deny</flux:button>
                </div>
            </div>
        @empty
            <p class="mt-4 text-sm text-zinc-500">No pending actions.</p>
        @endforelse
    </flux:card>
</div>