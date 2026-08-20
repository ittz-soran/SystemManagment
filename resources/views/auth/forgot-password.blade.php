<x-guest-layout>
    <p class="text-secondary small mb-3">
        {{ __('Forgot your password? Tell us your email address and we will send you a reset link.') }}
    </p>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" data-guard-submit>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror" required autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('Email password reset link') }}</button>

        <div class="text-center mt-3">
            <a class="small text-decoration-none" href="{{ route('login') }}">{{ __('Back to log in') }}</a>
        </div>
    </form>
</x-guest-layout>
