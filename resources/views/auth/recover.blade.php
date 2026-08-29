<x-guest-layout>
    {{--
        The way back in that does not go through the post.

        This system sends no email, so the usual reset link never arrives. What
        it has instead is the phone: the six digits it is showing right now
        prove who this is, and that is enough to set a new password.
    --}}
    <p class="text-secondary small mb-3">
        {{ __('Type the email address, the six digits your authenticator app is showing, and the new password you want.') }}
    </p>

    <form method="POST" action="{{ route('password.recover.update') }}" data-guard-submit data-hold-exempt>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   dir="ltr" required autofocus autocomplete="username">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="code" class="form-label">{{ __('The six digits, or a recovery code') }}</label>
            <input id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                   inputmode="numeric" autocomplete="one-time-code" dir="ltr" data-english-digits required>
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">
                {{ __('The code changes every thirty seconds. If the phone is lost, use one of the codes you wrote down.') }}
            </div>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('New password') }}</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">{{ __('New password again') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>

        <div class="d-flex align-items-center justify-content-between">
            <a href="{{ route('login') }}" class="small text-decoration-none">{{ __('Back to sign in') }}</a>
            <button class="btn btn-primary">{{ __('Set the new password') }}</button>
        </div>
    </form>

    <hr class="my-4">

    <p class="text-secondary small mb-0">
        {{ __('No authenticator on this account? Then only another admin can set a new password for you, from the Users screen.') }}
    </p>
</x-guest-layout>
