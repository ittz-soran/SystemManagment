@extends('layouts.app')

@section('title', __('Settings'))
@section('subheading', __('These values change invoices, costing and the edit window across the whole system'))

@section('content')
    <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" data-guard-submit>
        @csrf
        @method('PUT')

        <div class="row g-4">
            {{-- Layer 1 — shop info. Used on printed invoices, the login page,
                 and the browser title. --}}
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">{{ __('Shop information') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="shop_name" class="form-label">{{ __('Shop name') }}</label>
                            <input id="shop_name" name="shop_name" class="form-control @error('shop_name') is-invalid @enderror"
                                   value="{{ old('shop_name', setting('shop_name')) }}" required>
                            @error('shop_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            @foreach(['shop_name_ku' => __('Name in Sorani'), 'shop_name_ar' => __('Name in Arabic'), 'shop_name_fa' => __('Name in Persian')] as $key => $label)
                                <div class="col-md-4">
                                    <label for="{{ $key }}" class="form-label small">{{ $label }}</label>
                                    <input id="{{ $key }}" name="{{ $key }}" class="form-control form-control-sm"
                                           value="{{ old($key, setting($key)) }}">
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text mb-3">{{ __('Used on right-to-left invoices.') }}</div>

                        <div class="mb-3">
                            <label for="shop_address" class="form-label">{{ __('Address') }}</label>
                            <input id="shop_address" name="shop_address" class="form-control"
                                   value="{{ old('shop_address', setting('shop_address')) }}">
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="shop_phone" class="form-label">{{ __('Phone') }}</label>
                                <input id="shop_phone" name="shop_phone" class="form-control" dir="ltr"
                                       value="{{ old('shop_phone', setting('shop_phone')) }}">
                            </div>
                            <div class="col-6">
                                <label for="shop_phone_2" class="form-label">{{ __('Second phone') }}</label>
                                <input id="shop_phone_2" name="shop_phone_2" class="form-control" dir="ltr"
                                       value="{{ old('shop_phone_2', setting('shop_phone_2')) }}">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="shop_email" class="form-label">{{ __('Email') }}</label>
                                <input id="shop_email" type="email" name="shop_email" class="form-control @error('shop_email') is-invalid @enderror"
                                       dir="ltr" value="{{ old('shop_email', setting('shop_email')) }}">
                                @error('shop_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <label for="shop_website" class="form-label">{{ __('Website') }}</label>
                                <input id="shop_website" name="shop_website" class="form-control" dir="ltr"
                                       value="{{ old('shop_website', setting('shop_website')) }}">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="shop_logo" class="form-label">{{ __('Logo') }}</label>
                            @if(setting('shop_logo'))
                                <div class="mb-2">
                                    <img src="{{ setting('shop_logo') }}" alt="" height="48">
                                </div>
                            @endif
                            <input id="shop_logo" type="file" name="shop_logo" accept="image/*"
                                   class="form-control @error('shop_logo') is-invalid @enderror">
                            {{-- Section 8c: stored as a file with only the path in
                                 settings — never base64 in the database. --}}
                            <div class="form-text">{{ __('Printed on invoices and shown on the login page.') }}</div>
                            @error('shop_logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label for="invoice_footer" class="form-label">{{ __('Invoice footer') }}</label>
                            <textarea id="invoice_footer" name="invoice_footer" class="form-control" rows="2"
                                      placeholder="{{ __('Return policy, thank-you line…') }}">{{ old('invoice_footer', setting('invoice_footer')) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Layer 2 — appearance. Emitted as CSS custom properties. --}}
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">{{ __('Appearance') }}</div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="primary_color" class="form-label">{{ __('Primary colour') }}</label>
                                <input id="primary_color" type="color" name="primary_color"
                                       class="form-control form-control-color w-100"
                                       value="{{ old('primary_color', setting('primary_color', '#0d6efd')) }}">
                            </div>
                            <div class="col-6">
                                <label for="secondary_color" class="form-label">{{ __('Secondary colour') }}</label>
                                <input id="secondary_color" type="color" name="secondary_color"
                                       class="form-control form-control-color w-100"
                                       value="{{ old('secondary_color', setting('secondary_color', '#6c757d')) }}">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="font_family" class="form-label">{{ __('Font') }}</label>
                            <select id="font_family" name="font_family" class="form-select">
                                @foreach($fonts as $value => $label)
                                    <option value="{{ $value }}" @selected(old('font_family', setting('font_family')) === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            {{-- Section 8c: a short vetted list, because many Latin
                                 fonts have poor Arabic-script coverage and fall back
                                 to an ugly system font mid-sentence. --}}
                            <div class="form-text">
                                {{ __('All of these render Latin, Sorani, Arabic and Persian well.') }}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="sidebar_style" class="form-label">{{ __('Sidebar') }}</label>
                                <select id="sidebar_style" name="sidebar_style" class="form-select">
                                    <option value="expanded" @selected(setting('sidebar_style') === 'expanded')>{{ __('Expanded') }}</option>
                                    <option value="collapsed" @selected(setting('sidebar_style') === 'collapsed')>{{ __('Collapsed') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="default_theme" class="form-label">{{ __('Default theme') }}</label>
                                <select id="default_theme" name="default_theme" class="form-select">
                                    <option value="light" @selected(setting('default_theme') === 'light')>{{ __('Light') }}</option>
                                    <option value="dark" @selected(setting('default_theme') === 'dark')>{{ __('Dark') }}</option>
                                </select>
                                <div class="form-text">{{ __('The starting point for new users only.') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ __('Operation') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="timezone" class="form-label">{{ __('Timezone') }}</label>
                            <input id="timezone" name="timezone" class="form-control @error('timezone') is-invalid @enderror"
                                   dir="ltr" value="{{ old('timezone', setting('timezone', 'Asia/Baghdad')) }}" required>
                            {{-- Section 8b: without this a 10 PM sale is logged as
                                 the next day in UTC. --}}
                            <div class="form-text">{{ __('Affects daily reports and the 24-hour edit window.') }}</div>
                            @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="usd_rate" class="form-label">{{ __('USD rate') }}</label>
                                <div class="input-group">
                                    <span class="input-group-text">$1 =</span>
                                    <input id="usd_rate" type="number" step="1" min="1" name="usd_rate"
                                           class="form-control text-end" dir="ltr"
                                           value="{{ old('usd_rate', setting('usd_rate')) }}" required>
                                </div>
                                <div class="form-text">{{ __('Pre-fills the purchase form; editable per purchase.') }}</div>
                            </div>
                            <div class="col-6">
                                <label for="low_stock_threshold" class="form-label">{{ __('Low stock threshold') }}</label>
                                <input id="low_stock_threshold" type="number" step="1" min="0" name="low_stock_threshold"
                                       class="form-control text-end" dir="ltr"
                                       value="{{ old('low_stock_threshold', setting('low_stock_threshold')) }}" required>
                                <div class="form-text">{{ __('Used when a product has no reorder level of its own.') }}</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="books_closed_before" class="form-label">{{ __('Books closed before') }}</label>
                            <input id="books_closed_before" type="date" name="books_closed_before" class="form-control"
                                   value="{{ old('books_closed_before', setting('books_closed_before')) }}">
                            {{-- Section 8: once a month's profit has been reviewed,
                                 freeze it. --}}
                            <div class="form-text">
                                {{ __('Nothing dated before this can be created, edited or deleted. Leave blank while nothing is frozen.') }}
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="sku_prefix" class="form-label">{{ __('SKU prefix') }}</label>
                                <input id="sku_prefix" name="sku_prefix" class="form-control" dir="ltr"
                                       value="{{ old('sku_prefix', setting('sku_prefix')) }}" required>
                                <div class="form-text">{{ __('Auto-generated codes look like :example.', ['example' => setting('sku_prefix', 'SS').'65']) }}</div>
                            </div>
                            <div class="col-6">
                                <label for="date_format" class="form-label">{{ __('Date format') }}</label>
                                <input id="date_format" name="date_format" class="form-control" dir="ltr"
                                       value="{{ old('date_format', setting('date_format')) }}" required>
                                <div class="form-text" dir="ltr">{{ now()->format(setting('date_format', 'Y-m-d')) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Saving…') }}">
                {{ __('Save settings') }}
            </button>
        </div>
    </form>

    <div class="card mt-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-semibold">{{ __('Reset to defaults') }}</div>
                <div class="small text-secondary">{{ __('Puts every setting back to the value the system ships with.') }}</div>
            </div>
            <form action="{{ route('settings.reset') }}" method="POST"
                  onsubmit="return confirm(@js(__('Reset every setting to its default? Your shop name, logo and rates will be replaced.')))">
                @csrf
                <button class="btn btn-outline-danger">{{ __('Reset') }}</button>
            </form>
        </div>
    </div>
@endsection
