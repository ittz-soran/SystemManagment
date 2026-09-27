@extends('layouts.app')

@section('title', __('Shop health'))
@section('subheading', __('The books, the licence, the disk and the code — everything this shop needs to be right'))

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
    @php
        /*
         * ⚠️ **The verdict counts EVERYTHING on the page.** A green banner over
         * a licence that lapsed yesterday is the page telling a comfortable
         * lie.
         *
         * ⚠️ **And it says WHERE.** A first version merged the counts and
         * stopped there, so a shop with perfect books and no backup read "One
         * thing here cannot be right" above a wall of green ticks — a
         * shopkeeper would read that as their money being wrong. The books and
         * the machine fail for different reasons and a reader needs to know
         * which within a second of arriving.
         */
        $booksAreSound = $serious === 0 && $rebuildable === 0;

        $troubled = collect($health['sections'])
            ->filter(fn ($section) => collect($section['checks'])->contains(
                fn ($c) => in_array($c['severity'], [
                    \App\Services\ShopHealth::SERIOUS,
                    \App\Services\ShopHealth::NOTICE,
                ], true)))
            ->pluck('title');

        $serious += $health['serious'];
        $rebuildable += $health['notice'];
        $unavailable += $health['unavailable'];
    @endphp

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
                    {{-- Which half of the page the reader should go to. --}}
                    @if($booksAreSound && $troubled->isNotEmpty())
                        <div class="text-body">
                            {{ __('The books agree. It is :where that needs attention.', [
                                'where' => $troubled->join(__(', '), __(' and ')),
                            ]) }}
                        </div>
                    @elseif($troubled->isNotEmpty())
                        <div class="text-body">
                            {{ __('Look at Accounting, and at :where.', [
                                'where' => $troubled->join(__(', '), __(' and ')),
                            ]) }}
                        </div>
                    @endif

                    @if($unavailable > 0)
                        <span class="text-warning">{{ trans_choice(
                            '{1}One check could not run.|[2,*]:count checks could not run.',
                            $unavailable, ['count' => number_format($unavailable)]) }}</span>
                    @endif

                    {{ __(':checks checks · :rows records read · :seconds seconds', [
                        'checks' => number_format(count($checks) + $health['ok'] + $health['serious'] + $health['notice'] + $health['unavailable']),
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
        <div class="card-header">{{ __('Accounting') }}</div>
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

    {{--
        ⚠️ **The other half, which used to be reachable only from a terminal**
        — Soran, 2026-09-27. `php artisan shop:doctor` has always known about
        the licence, the folders and the compiled assets; a shopkeeper on cPanel
        has no terminal, so it may as well not have existed. Same check shape as
        the accounting list above, so this renders the same way.
    --}}
    @foreach($health['sections'] as $section)
        <div class="card mt-3">
            <div class="card-header">{{ $section['title'] }}</div>
            <ul class="list-group list-group-flush">
                @foreach($section['checks'] as $check)
                    @php [$variant, $icon, $word] = $look[$check['severity']]; @endphp

                    <li class="list-group-item">
                        <div class="d-flex flex-wrap align-items-baseline gap-2">
                            <span class="badge text-bg-{{ $variant }}">
                                <i class="bi bi-{{ $icon }} me-1"></i>{{ $word }}
                            </span>

                            <span class="fw-medium">{{ $check['title'] }}</span>

                            {{-- The reading itself, when there is one worth
                                 printing whether or not anything is wrong: how
                                 much disk, which PHP, when the backup ran. --}}
                            @if($check['note'])
                                <span class="ms-auto text-secondary small" dir="ltr">{{ $check['note'] }}</span>
                            @endif
                        </div>

                        <div class="small text-secondary mt-1">{{ $check['because'] }}</div>

                        @if($check['examples'] !== [])
                            <ul class="list-unstyled small mt-2 mb-0 ps-3 border-start border-{{ $variant }} border-2">
                                @foreach($check['examples'] as $example)
                                    <li class="mb-1">
                                        <span class="fw-medium">{{ $example['what'] }}</span>
                                        <span class="text-secondary">— {{ $example['says'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if($check['repair'])
                            <a href="{{ route($check['repair']) }}" class="btn btn-sm btn-outline-warning mt-2">
                                <i class="bi bi-gear me-1"></i>{{ __('Go and fix it') }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    {{-- ⚠️ **Not pass-or-fail, and not dressed as it.** Two hundred thousand
         movements is not a fault; it is the answer to "why has this got slow".
         A tick beside it would say something untrue. --}}
    <div class="card mt-3">
        <div class="card-header">{{ __('Numbers') }}</div>
        <div class="card-body">
            <p class="small text-secondary">
                {{ __('How big this shop is. Nothing here is right or wrong — it is the first thing to look at when something has become slow.') }}
            </p>
            <div class="row g-2 small">
                @foreach($health['numbers'] as $label => $value)
                    <div class="col-6 col-md-3 d-flex justify-content-between border-bottom py-1">
                        <span class="text-secondary">{{ $label }}</span>
                        <span class="fw-medium" dir="ltr">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <p class="text-secondary small mt-3 mb-0">
        {{ __('This page only reads. It never changes anything on its own — a contradiction is evidence, and repairing it before it has been read would destroy the only record of what went wrong.') }}
    </p>
@endsection
