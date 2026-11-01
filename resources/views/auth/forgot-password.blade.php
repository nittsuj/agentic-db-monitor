<x-layouts.guest title="Lupa password">
    <h2 class="card-title">Lupa password</h2>

    <p class="muted small">Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat password baru.</p>

    @if (session('status'))
        <p class="muted small" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="od-stack" style="gap:14px">
        @csrf

        <div class="od-stack" style="gap:6px">
            <label class="label" for="email">Email</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="btn primary">Kirim tautan reset</button>
    </form>

    <p class="muted small"><a href="{{ route('login') }}">Kembali ke halaman masuk</a></p>
</x-layouts.guest>
