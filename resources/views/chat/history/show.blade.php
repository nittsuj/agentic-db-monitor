<x-layouts.app>
    <x-slot:title>Riwayat chat — Agentic DB Monitor</x-slot:title>
    <x-slot:heading>Riwayat chat</x-slot:heading>
    <x-slot:subtitle>{{ $session->project->name }} · {{ $session->messages->count() }} pesan</x-slot:subtitle>

    <x-slot:actions>
        <a class="btn sm ghost" href="{{ route('chat.history') }}">Semua percakapan</a>
    </x-slot:actions>

    <section class="od-stack" style="gap:12px">
        @forelse ($session->messages as $message)
            <article class="card od-stack" style="gap:8px">
                <div class="od-row" style="gap:8px">
                    <span class="badge {{ $message->role->value === 'user' ? 'info' : '' }}">{{ $message->role->value }}</span>
                    <span class="muted small">{{ $message->created_at->format('Y-m-d H:i:s') }}</span>
                </div>

                @if ($message->role->value === 'assistant')
                    @if (trim((string) $message->content) === '')
                        <p class="muted small">Hanya memanggil alat, tanpa teks.</p>
                    @else
                        {{-- Same escaping as the chat: raw HTML in model output is not rendered. --}}
                        <div class="md">{!! \App\Livewire\Chat\ChatWindow::renderMarkdown($message->content) !!}</div>
                    @endif
                @else
                    <p style="white-space:pre-wrap;margin:0">{{ $message->content }}</p>
                @endif

                @if ($message->toolExecutionLogs->isNotEmpty())
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Agen</th>
                                <th>Alat</th>
                                <th>Status</th>
                                <th>Durasi</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($message->toolExecutionLogs as $log)
                                <tr>
                                    <td>{{ $log->loggable?->name ?? '—' }}</td>
                                    <td class="mono">{{ $log->tool_name }}</td>
                                    <td>
                                        <span class="badge {{ $log->status->value === 'success' ? 'ok' : 'breach' }}">{{ $log->status->value }}</span>
                                    </td>
                                    <td class="mono">{{ $log->duration_ms !== null ? $log->duration_ms.' ms' : '—' }}</td>
                                    <td>
                                        <details>
                                            <summary class="small">Argumen &amp; hasil</summary>
                                            <pre class="mono small" style="white-space:pre-wrap;max-width:520px">{{ json_encode($log->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            <pre class="mono small" style="white-space:pre-wrap;max-width:520px">{{ $log->error ?? $log->result }}</pre>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </article>
        @empty
            <p class="muted small">Percakapan ini belum berisi pesan.</p>
        @endforelse
    </section>
</x-layouts.app>
