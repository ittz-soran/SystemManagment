@extends('layouts.app')

@section('title', __('My preferences'))

@section('content')
    {{-- The most important link on this page, and the one nobody thinks about
         until the morning they cannot get in. --}}
    <div class="card mb-4 {{ auth()->user()->hasAuthenticator() ? '' : 'border-warning' }}">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-shield-check fs-3 {{ auth()->user()->hasAuthenticator() ? 'text-success' : 'text-warning' }}"></i>

            <div class="flex-grow-1">
                <div class="fw-semibold">{{ __('Way back in') }}</div>
                <div class="text-secondary small">
                    @if(auth()->user()->hasAuthenticator())
                        {{ __('An authenticator app can set a new password for this account if you forget it.') }}
                    @else
                        {{ __('This system sends no email, so a forgotten password cannot be reset by a link. Set up an authenticator app, or only another admin can let you back in.') }}
                    @endif
                </div>
            </div>

            <a href="{{ route('authenticator.show') }}"
               class="btn btn-sm {{ auth()->user()->hasAuthenticator() ? 'btn-outline-secondary' : 'btn-warning' }}">
                {{ auth()->user()->hasAuthenticator() ? __('Manage') : __('Set it up') }}
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            {{-- Section 8c layer 3: these belong to the person, not the shop. --}}
            <div class="card mb-4">
                <div class="card-header">{{ __('Preferences') }}</div>
                <div class="card-body">
                    <form action="{{ route('preferences.update') }}" method="POST" data-guard-submit>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="language" class="form-label">{{ __('Language') }}</label>
                            <select id="language" name="language" class="form-select">
                                @foreach(\App\Http\Middleware\SetUserPreferences::LANGUAGES as $code => $label)
                                    <option value="{{ $code }}" @selected(auth()->user()->language === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Sorani, Arabic and Persian switch the whole interface to right-to-left.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="theme" class="form-label">{{ __('Theme') }}</label>
                            <select id="theme" name="theme" class="form-select">
                                @foreach(['light' => __('Light'), 'dark' => __('Dark'), 'auto' => __('Auto — follow the system')] as $value => $label)
                                    <option value="{{ $value }}" @selected(auth()->user()->theme === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Two questions rather than one, because they have
                             separate answers: a shopkeeper reading the system in
                             Sorani may still want dates written the way his
                             supplier writes them, and a twelve-hour clock is a
                             habit rather than a language. --}}
                        <div class="mb-3">
                            <label for="date_language" class="form-label">{{ __('Dates and times') }}</label>
                            <select id="date_language" name="date_language" class="form-select">
                                @foreach([
                                    'interface' => __('In the language above'),
                                    'english' => __('Always in English'),
                                ] as $value => $label)
                                    <option value="{{ $value }}" @selected((auth()->user()->date_language ?? 'interface') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ __('Changes the weekday and month names on the clock — Wednesday 9 September, or چوارشەممە ٩ی سەرماوەز.') }}
                            </div>
                        </div>

                        <div class="mb-3 form-check form-switch">
                            {{-- The unchecked box has to reach the server too, or
                                 turning this off would look like not answering.
                                 The controller reads it with boolean(). --}}
                            <input type="hidden" name="clock_24_hour" value="0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="clock_24_hour" name="clock_24_hour" value="1"
                                   {{-- ?? false, because a User built by create() carries only what was
                                        passed to it: the column's database default has not been
                                        read back, and strict mode turns that into a 500 rather
                                        than a silent null. --}}
                                   @checked(auth()->user()->clock_24_hour ?? false)>
                            <label class="form-check-label" for="clock_24_hour">
                                {{ __('Twenty-four hour clock') }}
                            </label>
                            <div class="form-text">{{ __('Off, the clock shows am and pm.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="items_per_page" class="form-label">{{ __('Rows per page') }}</label>
                            <input id="items_per_page" type="number" min="5" max="200" name="items_per_page" dir="ltr"
                                   value="{{ auth()->user()->items_per_page }}" class="form-control text-end">
                        </div>

                        <button class="btn btn-primary">{{ __('Save preferences') }}</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header">{{ __('Profile') }}</div>
                <div class="card-body">
                    <form action="{{ route('profile.update') }}" method="POST" data-guard-submit>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="profile-name" class="form-label">{{ __('Name') }}</label>
                            <input id="profile-name" name="name" value="{{ old('name', auth()->user()->name) }}"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="profile-email" class="form-label">{{ __('Email') }}</label>
                            <input id="profile-email" type="email" name="email" dir="ltr"
                                   value="{{ old('email', auth()->user()->email) }}"
                                   class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button class="btn btn-primary">{{ __('Save profile') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
