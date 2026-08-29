<x-guest-layout>
    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" data-guard-submit data-hold-exempt>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   required autofocus autocomplete="username">
            {{-- Section 9b: inline field errors, never a toast. --}}
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('Password') }}</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="current-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="remember_me" name="remember">
            <label class="form-check-label" for="remember_me">{{ __('Remember me') }}</label>
        </div>

        <button type="submit" class="btn btn-primary w-100"
                data-submitting-text="{{ __('Logging in…') }}">{{ __('Log in') }}</button>

        {{-- The one that works. This system sends no email, so a reset link
             has nowhere to go — the phone is the way back in. --}}
        <div class="text-center mt-3">
            <a class="small text-decoration-none" href="{{ route('password.recover') }}">
                {{ __('Forgot your password?') }}
            </a>
        </div>
    </form>
</x-guest-layout>
