{{--
    The warning that has to arrive before the door closes.

    A shop that cannot record a sale will telephone the person who sold them
    the system, and the only thing that makes that call a short one is having
    been told for days beforehand. So this sits on every page from 80% on,
    and it does not dismiss.

    Only for people who can do something about it: a counter assistant seeing
    a storage warning on every sale learns to stop reading banners.
--}}
@php
    $quota = app(\App\Services\StorageQuota::class);
    $state = $quota->isLimited() ? $quota->state() : \App\Services\StorageQuota::OK;
@endphp

@if($state !== \App\Services\StorageQuota::OK && auth()->user()?->hasPermission('settings.manage'))
    <div class="alert alert-{{ $state === \App\Services\StorageQuota::WARNING ? 'warning' : 'danger' }} d-flex flex-wrap align-items-center gap-2 no-print">
        <i class="bi bi-hdd"></i>

        <div class="flex-grow-1">
            @if($state === \App\Services\StorageQuota::FULL)
                <strong>{{ __('Storage is full. Nothing new can be saved.') }}</strong>
                {{ __('Reading, printing and deleting still work.') }}
            @else
                <strong>{{ __('Storage is :percent% used.', ['percent' => $quota->percentUsed()]) }}</strong>
                {{ __('At the end of it, nothing new can be saved.') }}
            @endif

            <span dir="ltr">{{ __(':size left', ['size' => human_bytes($quota->remainingBytes())]) }}</span>
        </div>

        <a href="{{ route('settings.edit') }}#storage" class="btn btn-sm btn-outline-{{ $state === \App\Services\StorageQuota::WARNING ? 'warning' : 'danger' }}">
            {{ __('See what is using it') }}
        </a>
    </div>
@endif
