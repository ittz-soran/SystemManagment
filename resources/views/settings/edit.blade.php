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
                            @if(shop_logo())
                                <div class="mb-2">
                                    <img src="{{ shop_logo() }}" alt="" height="48">
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


        {{-- Section 4: an auto-generated barcode is never printed on the goods,
             so the shop prints its own label. These are the defaults the print
             modal opens with. --}}
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-upc-scan"></i>{{ __('Barcode labels') }}
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="label_printer" class="form-label">{{ __('Label printer') }}</label>

                        @if($detectedPrinters !== [])
                            <select id="label_printer" name="label_printer" class="form-select">
                                <option value="">{{ __('None — use the browser print dialog') }}</option>
                                @foreach($detectedPrinters as $path => $name)
                                    <option value="{{ $path }}" @selected(setting('label_printer') === $path)>
                                        {{ $name }}
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input id="label_printer" name="label_printer" class="form-control" dir="ltr"
                                   placeholder="\\localhost\XP-365B"
                                   value="{{ old('label_printer', setting('label_printer')) }}">
                        @endif

                        <div class="form-text">
                            {{-- The honest constraint, said once and plainly. --}}
                            {{ __('A web page cannot choose a printer on its own — the print dialog does that. Naming a shared printer here lets the server send the label straight to it instead, which only works from this machine.') }}
                            {{ __('Leave it empty to always use the print dialog.') }}
                        </div>
                    </div>

                    <div class="col-md-5">
                        <label for="label_size" class="form-label">{{ __('Label size') }}</label>
                        <select id="label_size" name="label_size" class="form-select">
                            @foreach($labelSizes as $key => $size)
                                <option value="{{ $key }}" @selected(old('label_size', setting('label_size')) === $key)>
                                    {{ __($size['label']) }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('Can be changed for a single print run.') }}</div>
                    </div>

                    <div class="col-12">
                        <div class="form-label">{{ __('Show on the label by default') }}</div>

                        <div class="d-flex flex-wrap gap-3">
                            @foreach([
                                'name' => __('Product name'),
                                'sku' => __('SKU'),
                                'price' => __('Sale price'),
                                'barcode_number' => __('The number under the bars'),
                                'shop' => __('Shop name'),
                            ] as $field => $caption)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           name="label_show_{{ $field }}" id="label_show_{{ $field }}"
                                           @checked($labelFields[$field] ?? false)>
                                    <label class="form-check-label" for="label_show_{{ $field }}">{{ $caption }}</label>
                                </div>
                            @endforeach
                        </div>

                        <div class="form-text">
                            {{ __('A price on the label means relabelling when the price changes.') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 10b ends with six assertions "to run globally, after every
             test". They live in the acceptance test, guarding the engine. This
             is the link to the same questions asked of the real shop — which is
             the version worth having after a power cut or a restored backup. --}}
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-shield-check"></i>{{ __('Data check') }}
            </div>
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="text-secondary small mb-0" style="max-width: 44rem">
                    {{ __('Reads every record and reports whether they still agree with one another — stock against its batches, balances against the ledger, totals against their lines, and every link the database itself cannot enforce. It changes nothing.') }}
                </div>

                <a href="{{ route('settings.data-check') }}" class="btn btn-outline-primary">
                    <i class="bi bi-clipboard-check me-1"></i>{{ __('Run the data check') }}
                </a>
            </div>
        </div>

        {{-- Section 8c: "Backup status — last backup time and a manual 'Back up
             now' button." Section 8b is the reason it is on this page at all:
             financial records are the shop's only proof of who owes what.

             The schedule and the folders live here too, so a shopkeeper can
             change them without a developer editing .env on the server. --}}
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-hdd"></i>{{ __('Backups') }}
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <span class="text-secondary">{{ __('Last backup') }}:</span>
                        @if($lastBackupAt)
                            <span class="fw-semibold" dir="ltr">{{ $lastBackupAt->format(setting('date_format', 'Y-m-d')).' '.$lastBackupAt->format('H:i') }}</span>
                            <span class="text-secondary small">({{ $lastBackupAt->diffForHumans() }})</span>
                            @if($lastBackupAt->lt(now()->subDays(2)))
                                <span class="badge text-bg-warning ms-1">{{ __('Out of date') }}</span>
                            @endif
                        @else
                            <span class="badge text-bg-danger">{{ __('Never') }}</span>
                        @endif

                        <div class="small text-secondary">
                            {{ __('There are :daily daily and :monthly monthly copies now.', [
                                'daily' => $dailyCopies,
                                'monthly' => $monthlyCopies,
                            ]) }}
                        </div>
                    </div>

                    {{-- Its own form, referenced by id: a form cannot be nested
                         inside another, and pressing this must not discard
                         whatever is half-typed in the fields below. --}}
                    <button type="submit" form="backup-now" class="btn btn-outline-primary" id="backup-now-button"
                            data-submitting-text="{{ __('Backing up… this can take a minute.') }}">
                        <i class="bi bi-download me-1"></i>{{ __('Back up now') }}
                    </button>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="backup_frequency" class="form-label">{{ __('How often') }}</label>
                        <select id="backup_frequency" name="backup_frequency" class="form-select">
                            <option value="daily" @selected(old('backup_frequency', setting('backup_frequency', 'daily')) === 'daily')>
                                {{ __('Every night') }}
                            </option>
                            <option value="weekly" @selected(old('backup_frequency', setting('backup_frequency', 'daily')) === 'weekly')>
                                {{ __('Once a week') }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label for="backup_time" class="form-label">{{ __('At') }}</label>
                        <input id="backup_time" type="time" name="backup_time" class="form-control @error('backup_time') is-invalid @enderror" dir="ltr"
                               value="{{ old('backup_time', setting('backup_time', '02:15')) }}" required>
                        @error('backup_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('In the shop\'s timezone, :zone.', ['zone' => setting('timezone', config('app.timezone'))]) }}</div>
                    </div>

                    <div class="col-md-4" id="backup-weekday-field">
                        <label for="backup_weekday" class="form-label">{{ __('On') }}</label>
                        <select id="backup_weekday" name="backup_weekday" class="form-select">
                            @foreach($weekdays as $value => $label)
                                <option value="{{ $value }}" @selected((int) old('backup_weekday', setting('backup_weekday', 5)) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="backup_keep_daily" class="form-label">{{ __('Daily copies to keep') }}</label>
                        <input id="backup_keep_daily" type="number" min="1" max="3650" name="backup_keep_daily"
                               class="form-control text-end @error('backup_keep_daily') is-invalid @enderror" dir="ltr"
                               value="{{ old('backup_keep_daily', setting('backup_keep_daily', config('backup.keep_daily'))) }}" required>
                        @error('backup_keep_daily')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="backup_keep_monthly" class="form-label">{{ __('Monthly copies to keep') }}</label>
                        <input id="backup_keep_monthly" type="number" min="1" max="120" name="backup_keep_monthly"
                               class="form-control text-end @error('backup_keep_monthly') is-invalid @enderror" dir="ltr"
                               value="{{ old('backup_keep_monthly', setting('backup_keep_monthly', config('backup.keep_monthly'))) }}" required>
                        @error('backup_keep_monthly')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('The first backup of each month is kept as that month\'s copy.') }}</div>
                    </div>

                    <div class="col-md-6">
                        <label for="backup_path" class="form-label">{{ __('Backup folder') }}</label>
                        <input id="backup_path" name="backup_path" class="form-control @error('backup_path') is-invalid @enderror" dir="ltr"
                               placeholder="{{ config('backup.local') }}"
                               value="{{ old('backup_path', setting('backup_path')) }}">
                        @error('backup_path')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('Leave blank for the default folder inside the app.') }}</div>
                    </div>

                    <div class="col-md-6">
                        <label for="backup_remote_path" class="form-label">{{ __('Off-machine folder') }}</label>
                        <input id="backup_remote_path" name="backup_remote_path" class="form-control @error('backup_remote_path') is-invalid @enderror" dir="ltr"
                               placeholder="D:/backups/store"
                               value="{{ old('backup_remote_path', setting('backup_remote_path', config('backup.remote'))) }}">
                        @error('backup_remote_path')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        @if($backupRemote)
                            <div class="form-text">{{ __('Every backup is copied here as well.') }}</div>
                        @else
                            {{-- Section 8b: "a dead disk should not take both." --}}
                            <div class="form-text text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                {{ __('Backups are being kept on the same disk as the database. Point this at a drive or share that is not this machine.') }}
                            </div>
                        @endif
                    </div>
                </div>

                <hr>

                <div class="small text-secondary">
                    {{-- Section 8b: "An untested backup is not a backup." --}}
                    {{ __('Restoring is a command on the server. Test it before go-live and every few months after — an untested backup is not a backup.') }}
                    <code class="ms-1" dir="ltr">php artisan backup:restore</code>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Saving…') }}">
                {{ __('Save settings') }}
            </button>
        </div>
    </form>

    <form action="{{ route('settings.backup') }}" method="POST" id="backup-now" class="d-none">
        @csrf
    </form>

    {{-- Everything below this line destroys something. Kept together, kept last,
         and each one says what it will do before it offers to do it. --}}
    <h2 class="h6 text-danger-emphasis mt-5 mb-2">
        <i class="bi bi-exclamation-octagon me-1"></i>{{ __('Danger zone') }}
    </h2>

    <div class="card border-danger-subtle">
        <div class="card-body">
            <div class="fw-semibold">{{ __('Start fresh') }}</div>
            <p class="small text-secondary mb-3">
                {{ __('Clears everything entered while testing — sales, purchases, returns, payments, expenses and all stock movements — and puts invoice numbers back to the beginning.') }}
                {{ __('Your products, categories, suppliers, customers and settings are kept, with stock set to zero.') }}
            </p>

            @if($resetBlocker)
                <div class="alert alert-secondary d-flex align-items-center gap-2 py-2 mb-0">
                    <i class="bi bi-lock"></i>
                    <span>{{ $resetBlocker }}</span>
                </div>
            @elseif($resetPreview === [])
                <div class="text-secondary small">{{ __('There is nothing to clear.') }}</div>
            @else
                <div class="table-responsive mb-3" style="max-width: 28rem">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @foreach($resetPreview as $table => $count)
                            <tr>
                                <td class="text-capitalize">{{ str_replace('_', ' ', $table) }}</td>
                                <td class="money">{{ number_format($count) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-shield-check me-1"></i>
                    {{ __('A backup is taken automatically before anything is removed. There is no other way back.') }}
                </div>

                <form action="{{ route('settings.reset-transactions') }}" method="POST" data-guard-submit
                      class="row g-2 align-items-end" style="max-width: 32rem">
                    @csrf
                    @method('DELETE')

                    <div class="col-sm-7">
                        <label for="confirmation" class="form-label small">
                            {{ __('Type :name to confirm', ['name' => setting('shop_name', config('app.name'))]) }}
                        </label>
                        <input id="confirmation" name="confirmation" required autocomplete="off"
                               class="form-control @error('confirmation') is-invalid @enderror">
                        @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-sm-5">
                        <button type="submit" class="btn btn-danger w-100"
                                data-submitting-text="{{ __('Clearing…') }}">
                            {{ __('Clear all transactions') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="card mt-3">
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

@push('scripts')
    <script>
        (() => {
            const frequency = document.getElementById('backup_frequency');
            const weekday = document.getElementById('backup-weekday-field');
            const button = document.getElementById('backup-now-button');

            // Which day only means anything for a weekly backup.
            const sync = () => weekday.classList.toggle('invisible', frequency.value !== 'weekly');

            frequency.addEventListener('change', sync);
            sync();

            // Section 9b: long actions show progress, not a frozen screen. The
            // shared guard cannot do this one, because the button lives outside
            // the form it submits.
            button.addEventListener('click', (event) => {
                // The form= attribute would submit it natively too; disabling
                // the button first would then cancel that submission, so this
                // takes over the whole job.
                event.preventDefault();

                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>'
                    + button.dataset.submittingText;
                document.getElementById('backup-now').requestSubmit();
            });
        })();
    </script>
@endpush
