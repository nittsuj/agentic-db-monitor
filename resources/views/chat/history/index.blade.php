<x-layouts.app>
    <x-slot:title>Riwayat chat — Agentic DB Monitor</x-slot:title>
    <x-slot:heading>Riwayat chat</x-slot:heading>
    <x-slot:subtitle>Percakapan Anda dan alat yang dipakai agen</x-slot:subtitle>

    <x-slot:actions>
        <a class="btn sm ghost" href="{{ route('chat') }}">Kembali ke chat</a>
    </x-slot:actions>

    <section class="card od-stack" style="gap:12px">
        @if ($sessions->isEmpty())
            <p class="muted small">Belum ada percakapan.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Pesan</th>
                        <th>Terakhir</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ $session->project->name }}</td>
                            <td class="mono">{{ $session->messages_count }}</td>
                            <td class="muted small">
                                {{ $session->messages_max_created_at
                                    ? \Illuminate\Support\Carbon::parse($session->messages_max_created_at)->diffForHumans()
                                    : '—' }}
                            </td>
                            <td><a class="btn sm ghost" href="{{ route('chat.history.show', $session) }}">Buka</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-layouts.app>
