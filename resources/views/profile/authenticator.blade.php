@extends('layouts.app')

@section("title", __("Way back in"))
@section("back")
    <x-back-link :to="route('profile.edit')" :label="__('My preferences')" />
@endsection
@section('subheading', __('So a forgotten password does not lock you out of the shop'))

@section('content')
    <div class="row g-4">
        <div class="col-lg-7">
            @if(session('recovery_codes'))
                {{-- Once, here, and never again. They are only worth anything
                     because nothing else holds them in the clear. --}}
                <div class="card border-warning mb-4">
                    <div class="card-header bg-warning-subtle">
                        <i class="bi bi-key me-1"></i>{{ __('Write these down now') }}
                    </div>
                    <div class="card-body">
                        <p class="mb-3">
                            {{ __('Eight codes, each one usable once. They are what gets you back in if the phone is lost or wiped. This is the only time they are shown — put them somewhere that is not this system.') }}
                        </p>

                        <div class="row row-cols-2 row-cols-sm-4 g-2 mb-3" dir="ltr">
                            @foreach(session('recovery_codes') as $code)
                                <div class="col">
                                    <code class="d-block border rounded text-center py-2">{{ $code }}</code>
                                </div>
                            @endforeach
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>{{ __('Print') }}
                        </button>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-shield-check"></i>{{ __('Authenticator app') }}

                    @if($user->hasAuthenticator())
                        <span class="badge text-bg-success ms-auto">{{ __('On') }}</span>
                    @else
                        <span class="badge text-bg-secondary ms-auto">{{ __('Off') }}</span>
                    @endif
                </div>

                <div class="card-body">
                    @if($user->hasAuthenticator())
                        <p>
                            {{ __('This account can be recovered with the app on your phone. If you forget the password, choose “Forgotten your password?” on the sign-in screen and type the six digits it is showing.') }}
                        </p>

                        <p class="text-secondary small">
                            {{ trans_choice(
                                '{0}No recovery codes left. Make a new set.|{1}One recovery code left.|[2,*]:count recovery codes left.',
                                count($user->two_factor_recovery_codes ?? []),
                                ['count' => count($user->two_factor_recovery_codes ?? [])]) }}
                            {{ __('Turned on :when.', ['when' => $user->two_factor_confirmed_at->format(setting('date_format', 'Y-m-d'))]) }}
                        </p>

                        <hr>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <form action="{{ route('authenticator.codes') }}" method="POST" data-guard-submit>
                                    @csrf
                                    <label for="codes-password" class="form-label small">{{ __('New recovery codes') }}</label>
                                    <input id="codes-password" type="password" name="password" class="form-control form-control-sm mb-2"
                                           placeholder="{{ __('Your current password') }}" required autocomplete="current-password">
                                    <button class="btn btn-sm btn-outline-secondary w-100">{{ __('Make a new set') }}</button>
                                </form>
                            </div>

                            <div class="col-md-6">
                                <form action="{{ route('authenticator.destroy') }}" method="POST" data-guard-submit
                                      onsubmit="return confirm(@js(__('Turn the authenticator off? After this there is no way back into this account without its password.')))">
                                    @csrf
                                    @method('DELETE')
                                    <label for="off-password" class="form-label small">{{ __('Turn it off') }}</label>
                                    <input id="off-password" type="password" name="password" class="form-control form-control-sm mb-2"
                                           placeholder="{{ __('Your current password') }}" required autocomplete="current-password">
                                    <button class="btn btn-sm btn-outline-danger w-100">{{ __('Turn off') }}</button>
                                </form>
                            </div>
                        </div>

                        @error('password')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                    @else
                        <p>
                            {{ __('There is no email on this system, so the usual “send me a link” cannot work. Instead, an app on your phone holds a code that changes every thirty seconds — and that code is what lets you set a new password if you ever forget this one.') }}
                        </p>

                        <ol class="mb-4">
                            <li class="mb-2">
                                {{ __('Install Google Authenticator, Microsoft Authenticator or any app like them.') }}
                            </li>
                            <li class="mb-2">
                                {{ __('Scan this square with it.') }}

                                <div class="my-3 p-3 bg-white d-inline-block rounded border">{!! $qr !!}</div>

                                <div class="small text-secondary">
                                    {{ __('If the camera will not focus, type this in by hand instead:') }}
                                    <code class="user-select-all d-inline-block" dir="ltr">{{ $readable }}</code>
                                </div>
                            </li>
                            <li>
                                {{ __('Type the six digits it shows, to prove it is working.') }}
                            </li>
                        </ol>

                        <form action="{{ route('authenticator.confirm') }}" method="POST" data-guard-submit>
                            @csrf
                            <div class="row g-2 align-items-end" style="max-width: 22rem">
                                <div class="col">
                                    <label for="code" class="form-label">{{ __('The six digits') }}</label>
                                    <input id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                                           inputmode="numeric" autocomplete="one-time-code" dir="ltr"
                                           data-english-digits maxlength="7" required autofocus>
                                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-primary">{{ __('Turn it on') }}</button>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card bg-body-tertiary">
                <div class="card-body small">
                    <h6>{{ __('Why this matters here') }}</h6>
                    <p class="mb-2">
                        {{ __('This system does not send email. Without an authenticator, a forgotten password can only be fixed by somebody else with an admin account — and if the only admin forgets theirs, nobody can get in at all.') }}
                    </p>
                    <p class="mb-0 text-secondary">
                        {{ __('It takes two minutes and it is only ever used when something has gone wrong. Signing in day to day does not change.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
