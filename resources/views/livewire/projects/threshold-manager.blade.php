<div>
    <flux:card>
        <flux:heading size="lg">Alert Thresholds</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-2">Metric</th>
                        <th class="py-2">Operator</th>
                        <th class="py-2">Value</th>
                        <th class="py-2">Enabled</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($thresholds as $threshold)
                        <tr class="border-t">
                            <td class="py-2">{{ $threshold->key }}</td>
                            <td class="py-2">{{ $threshold->operator->value }}</td>
                            <td class="py-2">{{ $threshold->value }}</td>
                            <td class="py-2">
                                <flux:switch wire:click="toggle('{{ $threshold->id }}')" :checked="$threshold->enabled" />
                            </td>
                            <td class="py-2 flex gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $threshold->id }}')">Edit</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $threshold->id }}')">Delete</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form wire:submit="save" class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-4">
            <flux:select wire:model="key" label="Metric">
                <flux:select.option value="connection_pool_used">connection_pool_used</flux:select.option>
                <flux:select.option value="slow_queries_per_min">slow_queries_per_min</flux:select.option>
            </flux:select>

            <flux:select wire:model="operator" label="Operator">
                @foreach ($operators as $op)
                    <flux:select.option value="{{ $op->value }}">{{ $op->value }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="value" label="Value" type="number" step="0.1" />

            <div class="flex items-end">
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? 'Update' : 'Add' }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>