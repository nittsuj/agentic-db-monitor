<div class="flex h-[calc(100vh-8rem)] min-h-96 flex-col border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    <div class="flex-1 overflow-y-auto space-y-3 p-4" aria-live="polite">
        @foreach ($messages as $m)
            <div class="{{ $m['role'] === 'user' ? 'text-right' : 'text-left' }}">
                <div class="inline-block max-w-[80%] rounded-lg px-3 py-2 {{ $m['role'] === 'user' ? 'bg-blue-500 text-white' : 'bg-zinc-100 dark:bg-zinc-800' }}">
                    {{ $m['content'] }}
                </div>
            </div>
        @endforeach
        @if ($assistantContent !== '')
            <div class="text-left">
                <div wire:stream="assistantContent" class="inline-block max-w-[80%] rounded-lg px-3 py-2 bg-zinc-100 dark:bg-zinc-800">
                    {{ $assistantContent }}
                </div>
            </div>
        @endif
        @if ($isSending)
            <div class="text-left" role="status">
                <div class="inline-flex items-center gap-2 bg-zinc-100 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <span class="size-2 animate-pulse rounded-full bg-emerald-500"></span>
                    Checking your database…
                </div>
            </div>
        @endif
        @if ($errorMessage !== null)
            <div class="border-s-2 border-red-500 bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ $errorMessage }}
            </div>
        @endif
    </div>

    <form wire:submit="send" class="flex gap-2 border-t border-zinc-200 p-3 dark:border-zinc-700">
        <input wire:model="input" type="text" class="min-w-0 flex-1 border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder:text-zinc-500 focus:border-emerald-600 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="Ask about your database…" aria-label="Message" />
        <button type="submit" wire:loading.attr="disabled" wire:target="send" @disabled($isSending) class="inline-flex items-center gap-2 bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove wire:target="send">Send</span>
            <span wire:loading wire:target="send">Working…</span>
        </button>
    </form>
</div>