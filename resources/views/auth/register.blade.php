<x-layouts.guest title="Daftar">
    <h2 class="card-title">Daftar</h2>

    <form method="POST" action="{{ route('register') }}" class="od-stack" style="gap:14px">
        @csrf

        <div class="od-stack" style="gap:6px">
            <label class="label" for="name">Nama</label>
            <input id="name" class="input" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
            @error('name') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="email">Email</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            @error('email') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="password">Password</label>
            <input id="password" class="input" type="password" name="password" required autocomplete="new-password">
            @error('password') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="password_confirmation">Konfirmasi password</label>
            <input id="password_confirmation" class="input" type="password" name="password_confirmation" required autocomplete="new-password">
            @error('password_confirmation') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="btn primary">Daftar</button>
    </form>

    <p class="muted small">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
</x-layouts.guest>
