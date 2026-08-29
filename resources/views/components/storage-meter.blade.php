@props(['compact' => false])

@php
    $quota = app(\App\Services\StorageQuota::class);
@endphp

{{-- An install that was not sold on a plan carries none of a plan's furniture. --}}
@if($quota->isLimited())
    @php
        $state = $quota->state();
        $percent = $quota->percentUsed();
        $breakdown = $quota->breakdown();

        $variant = match ($state) {
            \App\Services\StorageQuota::FULL => 'danger',
            \App\Services\StorageQuota::CRITICAL => 'danger',
            \App\Services\StorageQuota::WARNING => 'warning',
            default => 'success',
        };
    @endphp

    <div class="card mt-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-hdd"></i>{{ __('Storage') }}
        </div>

        <div class="card-body">
            <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                <div>
                    <span class="fs-4 fw-semibold" dir="ltr">{{ human_bytes($quota->usedBytes()) }}</span>
                    <span class="text-secondary">
                        {{ __('of :limit used', ['limit' => human_bytes($quota->limitBytes())]) }}
                    </span>
                </div>

                <div class="text-{{ $variant }} fw-medium" dir="ltr">
                    {{ __(':size left', ['size' => human_bytes($quota->remainingBytes())]) }}
                </div>
            </div>

            <div class="progress" role="progressbar"
                 aria-label="{{ __('Storage') }}"
                 aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
                 style="height: .75rem">
                <div class="progress-bar bg-{{ $variant }}" style="width: {{ max($percent, 1) }}%"></div>
            </div>

            <div class="text-secondary small mt-2" dir="ltr">{{ $percent }}%</div>

            @unless($compact)
                {{-- Where the space actually went. Backups are usually the
                     largest of the three and the easiest to shorten, so it is
                     worth being able to see that rather than guess it. --}}
                <div class="row g-3 mt-1">
                    @foreach([
                        'database' => __('The shop’s records'),
                        'backups' => __('Backups kept'),
                        'uploads' => __('Uploaded files'),
                    ] as $part => $label)
                        <div class="col-sm-4">
                            <div class="border rounded p-2 h-100">
                                <div class="text-secondary small">{{ $label }}</div>
                                <div class="fw-semibold" dir="ltr">{{ human_bytes($breakdown[$part]) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($state === \App\Services\StorageQuota::FULL)
                    <div class="alert alert-danger mt-3 mb-0">
                        {{ __('There is no room left, so nothing new can be saved. Reading, printing and deleting all still work. Shorten how many backups are kept below, delete what is no longer needed, or ask whoever provides this system for more space.') }}
                    </div>
                @elseif($state !== \App\Services\StorageQuota::OK)
                    <div class="alert alert-{{ $variant }} mt-3 mb-0">
                        {{ __('When this reaches the end, the shop can still read, print and delete — but nothing new can be saved until there is room. Worth sorting out before that happens.') }}
                    </div>
                @endif
            @endunless
        </div>
    </div>
@endif
