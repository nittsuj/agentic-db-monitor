<x-layouts.guest title="Masuk">
    <h2 class="card-title">Masuk</h2>

    @if (session('status'))
        <p class="muted small" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="od-stack" style="gap:14px">
        @csrf

        <div class="od-stack" style="gap:6px">
            <label class="label" for="email">Email</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="password">Password</label>
            <input id="password" class="input" type="password" name="password" required autocomplete="current-password">
            @error('password') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <label class="od-row small" for="remember_me" style="gap:8px">
            <input id="remember_me" type="checkbox" name="remember">
            <span>Ingat saya</span>
        </label>

        <button type="submit" class="btn primary">Masuk</button>
    </form>

    <p class="muted small"><a href="{{ route('password.request') }}">Lupa password?</a></p>

    <p class="muted small">Belum punya akun? <a href="{{ route('register') }}">Daftar</a></p>
</x-layouts.guest>
