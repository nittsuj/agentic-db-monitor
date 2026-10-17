<div class="flex flex-col h-full">
    <div class="flex-1 overflow-y-auto space-y-3 p-4">
        @foreach ($messages as $m)
            <div class="{{ $m['role'] === 'user' ? 'text-right' : 'text-left' }}">
                <div class="inline-block max-w-[80%] rounded-lg px-3 py-2 {{ $m['role'] === 'user' ? 'bg-blue-500 text-white' : 'bg-zinc-100 dark:bg-zinc-800' }}">
                    {{ $m['content'] }}
                </div>
            </div>
        @endforeach
        @if ($assistantContent !== '')
            <div class="text-left">
                <div class="inline-block max-w-[80%] rounded-lg px-3 py-2 bg-zinc-100 dark:bg-zinc-800">
                    {{ $assistantContent }}
                </div>
            </div>
        @endif
    </div>

    <form wire:submit="send" class="border-t p-3 flex gap-2">
        <input wire:model="input" type="text" class="flex-1 rounded border px-3 py-2" placeholder="Ask about your database…" />
        <button type="submit" class="rounded bg-blue-500 px-4 py-2 text-white">Send</button>
    </form>
</div>