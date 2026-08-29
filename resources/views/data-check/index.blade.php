@extends('layouts.app')

@section('title', __('Data check'))
@section('subheading', __('Whether the shop’s records still agree with one another'))

@section('content')
    @php
        // Serious first, then what can be rebuilt, then everything that passed —
        // a page whose first line is a green tick nobody needs to read has
        // buried the one line somebody does.
        $order = [
            \App\Services\DataIntegrityService::SERIOUS => 0,
            \App\Services\DataIntegrityService::REBUILDABLE => 1,
            \App\Services\DataIntegrityService::UNAVAILABLE => 2,
            \App\Services\DataIntegrityService::OK => 3,
        ];

        $sorted = collect($checks)->sortBy(fn ($c) => $order[$c['severity']])->values();

        $look = [
            \App\Services\DataIntegrityService::SERIOUS => ['danger', 'exclamation-octagon', __('Needs a person')],
            \App\Services\DataIntegrityService::REBUILDABLE => ['warning', 'exclamation-triangle', __('Can be rebuilt')],
            \App\Services\DataIntegrityService::UNAVAILABLE => ['secondary', 'info-circle', __('Did not run')],
            \App\Services\DataIntegrityService::OK => ['success', 'check-circle', __('Agrees')],
        ];
    @endphp

    {{-- The verdict, before any of the detail. --}}
    <div class="card mb-3 border-{{ $serious > 0 ? 'danger' : ($rebuildable > 0 ? 'warning' : 'success') }}">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-{{ $serious > 0 ? 'exclamation-octagon' : ($rebuildable > 0 ? 'exclamation-triangle' : 'shield-check') }} fs-1
                      text-{{ $serious > 0 ? 'danger' : ($rebuildable > 0 ? 'warning' : 'success') }}"></i>

            <div class="flex-grow-1">
                <div class="fs-5 fw-semibold">
                    @if($serious > 0)
                        {{ trans_choice(
                            '{1}One thing here cannot be right.|[2,*]:count things here cannot be right.',
                            $serious, ['count' => number_format($serious)]) }}
                    @elseif($rebuildable > 0)
                        {{ __('Nothing is broken. Something needs recalculating.') }}
                    @elseif($unavailable > 0)
                        {{ __('Everything that could be checked agrees.') }}
                    @else
                        {{ __('Everything agrees.') }}
                    @endif
                </div>

                <div class="text-secondary small">
                    @if($unavailable > 0)
                        <span class="text-warning">{{ trans_choice(
                            '{1}One check could not run.|[2,*]:count checks could not run.',
                            $unavailable, ['count' => number_format($unavailable)]) }}</span>
                    @endif

                    {{ __(':checks checks · :rows records read · :seconds seconds', [
                        'checks' => number_format(count($checks)),
                        'rows' => number_format($rows),
                        'seconds' => $ran_for,
                    ]) }}
                </div>
            </div>

            <a href="{{ route('settings.data-check') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-repeat me-1"></i>{{ __('Run again') }}
            </a>
        </div>
    </div>

    {{-- What the two answers mean, said once rather than on every row. --}}
    <div class="alert alert-secondary small">
        <div class="d-flex align-items-baseline gap-2 mb-1">
            <span class="badge text-bg-warning">{{ __('Can be rebuilt') }}</span>
            <span>{{ __('A figure the system works out from something else has drifted. The real records are intact and the figure can be recalculated.') }}</span>
        </div>
        <div class="d-flex align-items-baseline gap-2">
            <span class="badge text-bg-danger">{{ __('Needs a person') }}</span>
            <span>{{ __('Two records disagree and nothing else can say which is right. Take a backup before changing anything, and tell whoever built this what the rows say.') }}</span>
        </div>
    </div>

    <div class="card">
        <ul class="list-group list-group-flush">
            @foreach($sorted as $check)
                @php [$variant, $icon, $word] = $look[$check['severity']]; @endphp

                <li class="list-group-item">
                    <div class="d-flex flex-wrap align-items-baseline gap-2">
                        <span class="badge text-bg-{{ $variant }}">
                            <i class="bi bi-{{ $icon }} me-1"></i>{{ $word }}
                        </span>

                        <span class="fw-medium">{{ $check['title'] }}</span>

                        <span class="badge border border-secondary-subtle text-secondary fw-normal">{{ $check['group'] }}</span>

                        <span class="ms-auto text-secondary small" dir="ltr">
                            @if($check['severity'] === \App\Services\DataIntegrityService::UNAVAILABLE)
                                —
                            @elseif($check['failed'] > 0)
                                {{ __(':count of :examined', [
                                    'count' => number_format($check['failed']),
                                    'examined' => number_format($check['examined']),
                                ]) }}
                            @else
                                {{ number_format($check['examined']) }}
                            @endif
                        </span>
                    </div>

                    <div class="small text-secondary mt-1">{{ $check['because'] }}</div>

                    @if($check['examples'] !== [])
                        <ul class="list-unstyled small mt-2 mb-0 ps-3 border-start border-{{ $variant }} border-2">
                            @foreach($check['examples'] as $example)
                                <li class="mb-1">
                                    @if($example['url'])
                                        <a href="{{ $example['url'] }}" class="fw-medium text-decoration-none">{{ $example['what'] }}</a>
                                    @else
                                        <span class="fw-medium">{{ $example['what'] }}</span>
                                    @endif
                                    <span class="text-secondary">— {{ $example['says'] }}</span>
                                </li>
                            @endforeach

                            @if($check['more'] > 0)
                                <li class="text-secondary">
                                    {{ trans_choice('{1}and one more|[2,*]and :count more', $check['more'],
                                        ['count' => number_format($check['more'])]) }}
                                </li>
                            @endif
                        </ul>
                    @endif

                    @if($check['repair'] === 'stock.recheck')
                        <a href="{{ route('stock.recheck') }}" class="btn btn-sm btn-outline-warning mt-2">
                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('Recheck stock') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <p class="text-secondary small mt-3 mb-0">
        {{ __('This page only reads. It never changes anything on its own — a contradiction is evidence, and repairing it before it has been read would destroy the only record of what went wrong.') }}
    </p>
@endsection
