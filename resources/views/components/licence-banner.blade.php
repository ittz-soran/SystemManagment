{{--
    The warning that has to arrive before the door closes — the same rule as
    the storage one, and for the same reason: a shop that cannot record a sale
    telephones the person who sold them the system, and the only thing that
    makes that a short call is having been told for a fortnight beforehand.

    Only for people who can act on it. A counter assistant who sees a licence
    warning on every sale learns to stop reading banners.
--}}
@php
    $licence = app(\App\Services\Licence::class);
    $state = $licence->isRequired() ? $licence->state() : \App\Services\Licence::UNLICENSED;
    $noisy = [
        \App\Services\Licence::EXPIRING, \App\Services\Licence::GRACE,
        \App\Services\Licence::EXPIRED, \App\Services\Licence::MISSING,
        \App\Services\Licence::INVALID, \App\Services\Licence::WRONG_HOST,
    ];
@endphp

@if(in_array($state, $noisy, true) && auth()->user()?->hasPermission('settings.manage'))
    @php
        $found = $licence->check();
        $variant = $licence->allowsWriting() ? 'warning' : 'danger';
    @endphp

    <div class="alert alert-{{ $variant }} d-flex flex-wrap align-items-center gap-2 no-print">
        <i class="bi bi-patch-check"></i>

        <div class="flex-grow-1">
            @if($state === \App\Services\Licence::EXPIRING)
                <strong>{{ trans_choice(
                    '{0}The licence ends today.|{1}The licence ends tomorrow.|[2,*]The licence ends in :count days.',
                    $found['days_left'], ['count' => $found['days_left']]) }}</strong>
                {{ __('After that, nothing new can be saved.') }}
            @elseif($state === \App\Services\Licence::GRACE)
                <strong>{{ __('The licence has passed its date.') }}</strong>
                {{ __('Everything still works for a few more days, then nothing new can be saved.') }}
            @else
                <strong>{{ __('The licence is not valid. Nothing new can be saved.') }}</strong>
                {{ __('Reading, printing and deleting still work.') }}
            @endif
        </div>

        <a href="{{ route('settings.edit') }}#licence" class="btn btn-sm btn-outline-{{ $variant }}">
            {{ __('Details') }}
        </a>
    </div>
@endif
