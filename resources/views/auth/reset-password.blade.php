<x-layouts.guest title="Buat password baru">
    <h2 class="card-title">Buat password baru</h2>

    <form method="POST" action="{{ route('password.store') }}" class="od-stack" style="gap:14px">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="od-stack" style="gap:6px">
            <label class="label" for="email">Email</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username">
            @error('email') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="password">Password baru</label>
            <input id="password" class="input" type="password" name="password" required autocomplete="new-password">
            @error('password') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <div class="od-stack" style="gap:6px">
            <label class="label" for="password_confirmation">Konfirmasi password</label>
            <input id="password_confirmation" class="input" type="password" name="password_confirmation" required autocomplete="new-password">
            @error('password_confirmation') <span class="small" style="color:var(--adbm-danger)">{{ $message }}</span> @enderror
        </div>

        <button type="submit" class="btn primary">Simpan password</button>
    </form>
</x-layouts.guest>
