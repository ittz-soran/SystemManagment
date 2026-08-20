<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}" data-guard-submit>
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                   class="form-control @error('email') is-invalid @enderror" required autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">{{ __('New password') }}</label>
            <input id="password" type="password" name="password" autocomplete="new-password"
                   class="form-control @error('password') is-invalid @enderror" required>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   autocomplete="new-password" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('Reset password') }}</button>
    </form>
</x-guest-layout>
