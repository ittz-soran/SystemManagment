{{--
    This shop is running code its database has not caught up with.

    The shared codebase makes that a normal state for a few minutes and a
    dangerous one for a few days: `git pull` moves every shop's code at once,
    and each database waits for `shop:update`. In between, the first screen to
    write a new column answers 500 with nothing to explain it.

    Shown to whoever can act on it — the same rule as the storage banner. A
    counter assistant seeing this on every sale learns to stop reading banners,
    and cannot run the command anyway.

    It does not dismiss, and it says the command, because the person reading it
    is usually not the person who will type it.
--}}
@php($schema = app(\App\Services\SchemaVersion::class))

@if($schema->isBehind() && auth()->user()?->hasPermission('settings.manage'))
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 no-print">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>

        <div class="flex-grow-1">
            <strong>{{ __('This shop’s database is behind the system.') }}</strong>
            {{ __('Some screens will fail until it is brought up to date. Nothing has been lost.') }}
        </div>

        <code class="small" dir="ltr">php artisan shop:update</code>
    </div>
@endif
