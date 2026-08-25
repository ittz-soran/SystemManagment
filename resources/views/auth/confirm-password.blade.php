<x-guest-layout>
    <p class="text-secondary small mb-3">
        {{ __('This is a secure area. Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" data-guard-submit data-hold-exempt>
        @csrf

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" autocomplete="current-password"
                   class="form-control @error('password') is-invalid @enderror" required autofocus>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('Confirm') }}</button>
    </form>
</x-guest-layout>
