@extends('layouts.app')

@section('title', __('My preferences'))

@section('content')
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
