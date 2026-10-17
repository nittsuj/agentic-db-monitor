<div>
    <flux:modal name="create-project-modal" class="md:w-[32rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Add Database Profile</flux:heading>
                <flux:subheading>Connect a target MySQL or PostgreSQL database to monitor.</flux:subheading>
            </div>

            @if ($errorMessage)
                <flux:callout variant="danger" icon="exclamation-circle">
                    <flux:callout.heading>Connection check failed</flux:callout.heading>
                    <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            @if (count($warnings) > 0)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>Permission recommendations</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc ps-4 space-y-1 text-xs">
                            @foreach ($warnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            <form wire:submit="save" class="space-y-4">
                <flux:input wire:model="name" label="Workspace / Project Name" placeholder="e.g. Production MySQL" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model.live="driver" label="Database Driver">
                        <flux:select.option value="mysql">MySQL</flux:select.option>
                        <flux:select.option value="pgsql">PostgreSQL</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="port" label="Port" type="number" required />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="host" label="Host" placeholder="127.0.0.1" required />
                    <flux:input wire:model="database" label="Database Name" placeholder="mydb" required />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="username" label="Username" placeholder="root" required />
                    <flux:input wire:model="password" label="Password" type="password" placeholder="••••••••" />
                </div>

                <div class="flex justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Test & Save Profile</span>
                        <span wire:loading>Testing Privileges...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
