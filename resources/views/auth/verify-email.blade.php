<x-guest-layout>
    <p class="text-secondary small mb-3">
        {{ __('Thanks for signing up. Please confirm your email address by clicking the link we just sent you. If you did not get the email, we will gladly send another.') }}
    </p>

    @if(session('status') === 'verification-link-sent')
        <div class="alert alert-success py-2">
            {{ __('A new verification link has been sent to your email address.') }}
        </div>
    @endif

    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('verification.send') }}" class="flex-grow-1" data-guard-submit>
            @csrf
            <button type="submit" class="btn btn-primary w-100">{{ __('Resend verification email') }}</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary">{{ __('Log out') }}</button>
        </form>
    </div>
</x-guest-layout>
