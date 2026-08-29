@php
    $licence = app(\App\Services\Licence::class);
    $found = $licence->check();
    $state = $found['state'];
@endphp

{{-- A copy that was never sold carries none of this. --}}
@if($licence->isRequired())
    @php
        $variant = match ($state) {
            \App\Services\Licence::VALID => 'success',
            \App\Services\Licence::EXPIRING, \App\Services\Licence::GRACE => 'warning',
            default => 'danger',
        };
    @endphp

    <div class="card mt-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-patch-check"></i>{{ __('Licence') }}

            <span class="badge text-bg-{{ $variant }} ms-auto">
                {{ match ($state) {
                    \App\Services\Licence::VALID => __('Active'),
                    \App\Services\Licence::EXPIRING => __('Ending soon'),
                    \App\Services\Licence::GRACE => __('Past its date'),
                    \App\Services\Licence::EXPIRED => __('Expired'),
                    \App\Services\Licence::WRONG_HOST => __('Wrong web address'),
                    \App\Services\Licence::INVALID => __('Cannot be read'),
                    default => __('Missing'),
                } }}
            </span>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-4">
                    <div class="text-secondary small">{{ __('Licensed to') }}</div>
                    <div class="fw-semibold">{{ $found['shop'] ?? '—' }}</div>
                </div>

                <div class="col-sm-4">
                    <div class="text-secondary small">{{ __('Valid until') }}</div>
                    <div class="fw-semibold" dir="ltr">
                        {{ $found['expires']?->format(setting('date_format', 'Y-m-d')) ?? __('No end date') }}
                    </div>
                </div>

                <div class="col-sm-4">
                    <div class="text-secondary small">{{ __('Reference') }}</div>
                    <div class="fw-semibold" dir="ltr">{{ $found['id'] ?? '—' }}</div>
                </div>
            </div>

            @if($found['days_left'] !== null && $found['days_left'] >= 0 && $state !== \App\Services\Licence::VALID)
                <div class="alert alert-warning mt-3 mb-0">
                    {{ trans_choice(
                        '{0}This is the last day.|{1}One day left.|[2,*]:count days left.',
                        $found['days_left'], ['count' => $found['days_left']]) }}
                    {{ __('When it runs out, the shop can still read, print and delete — but nothing new can be saved.') }}
                </div>
            @elseif($state === \App\Services\Licence::GRACE)
                <div class="alert alert-warning mt-3 mb-0">
                    {{ __('The date has passed. Everything still works for a few more days, then nothing new can be saved.') }}
                </div>
            @elseif(! $licence->allowsWriting())
                <div class="alert alert-danger mt-3 mb-0">
                    {{ match ($state) {
                        \App\Services\Licence::WRONG_HOST => __('This licence was issued for a different web address, so nothing new can be saved.'),
                        \App\Services\Licence::INVALID => __('This licence cannot be read, so nothing new can be saved.'),
                        \App\Services\Licence::EXPIRED => __('This licence has run out, so nothing new can be saved.'),
                        default => __('There is no licence on this system, so nothing new can be saved.'),
                    } }}
                    {{ __('Reading, printing and deleting all still work. Contact whoever provides this system.') }}
                </div>
            @endif
        </div>
    </div>
@endif
