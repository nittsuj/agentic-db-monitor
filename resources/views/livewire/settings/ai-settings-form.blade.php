<div>
    <flux:card class="max-w-2xl">
        <flux:heading size="lg">AI Provider Settings</flux:heading>

        <form wire:submit="save" class="mt-4 space-y-4">
            <flux:select wire:model="provider" label="Provider">
                <flux:select.option value="ollama">Ollama</flux:select.option>
            </flux:select>

            <flux:select wire:model="model" label="Model">
                @foreach (['llama3.1', 'llama3.2', 'qwen2.5'] as $m)
                    <flux:select.option value="{{ $m }}">{{ $m }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="base_url" label="Base URL" type="url" />

            <flux:input
                wire:model="api_key_input"
                label="API Key"
                type="password"
                placeholder="{{ $has_existing_key ? '••••••••' : 'Enter key' }}"
            />

            <flux:button type="submit" variant="primary">Save</flux:button>
        </form>

        @if (session('status'))
            <flux:callout variant="success" class="mt-4">{{ session('status') }}</flux:callout>
        @endif
    </flux:card>
</div>