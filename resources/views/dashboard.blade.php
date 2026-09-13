@extends('layouts.app')

@section('title', __('Dashboard'))

@section('actions')
    <x-currency-lens :label="__('Read in')" />
@endsection

@section('content')
    {{-- ⚠️ Before any figure below it: these are the books divided by today's
         rate, not what was recorded. See components/lens-note. --}}
    <x-lens-note :lens="$lens" />

    @isset($setup)
        @include('partials.setup-checklist', ['setup' => $setup])
    @endisset

    {{--
        Every panel is behind the permission of the screen it summarises — see
        DashboardController. A reader who holds none of them is not shown an
        empty shell; they are told plainly that this page has nothing for them.
    --}}
    <div class="row g-3 mb-4">
        @foreach($cards as $card)
            <div class="col-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        {{-- The note rides on the label's line, at the far end.
                             Moved there by Soran, 2026-09-12: it used to sit
                             between the figure and the chart, which pushed the
                             chart down into the card's edge and left the top
                             corner empty. Both are secondary text, so they
                             belong on the same line — and `justify-content-
                             between` puts the note on the correct side in all
                             four languages without a rule per direction. --}}
                        <div class="d-flex align-items-center justify-content-between gap-2 text-secondary small mb-1">
                            <span class="d-inline-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-{{ $card['icon'] }}"></i>{{ $card['label'] }}
                            </span>
                            @if($card['note'])
                                <span class="text-nowrap">{{ $card['note'] }}</span>
                            @endif
                        </div>

                        <div class="fs-4 fw-semibold money">
                            {{ $card['cost'] ? cost_money($card['value'], true, $lens) : money_if($card['value'] !== null, $card['value'], true, $lens) }}
                        </div>

                        {{-- The shape of the last four weeks behind the figure:
                             the tile says what today was, this says whether
                             today was normal. Absent, not empty, when the
                             reader may not see the figure — see the controller.
                             In the measure's own colour, which is the colour it
                             wears on the chart below. --}}
                        @if(($card['spark'] ?? null) !== null)
                            <x-chart.spark :values="$card['spark']"
                                           :tone="$card['tone'] ?? null"
                                           :level="$card['level'] ?? false" />
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- The tiles above say what today was. This says whether today was normal,
         which is the question somebody standing at the counter actually has. --}}
    @isset($trend)
        <div class="mb-4">
            <x-chart.trend :lens="$lens"
                :title="($trendWindows[$trendWindow] ?? $trendWindows['weeks'])['title']"
                :subtitle="__('Hover any day to read every line at once.')"
                :labels="$trend['labels']"
                :notes="$trend['notes']"
                :series="$trend['series']"
                :level="$trend['level']"
                :height="200">
                <x-slot:periods>
                    @foreach($trendWindows as $key => $window)
                        <a href="{{ request()->fullUrlWithQuery(['trend' => $key]) }}"
                           class="btn {{ $key === $trendWindow ? 'btn-secondary' : 'btn-outline-secondary' }}"
                           @if($key === $trendWindow) aria-current="true" @endif>{{ $window['label'] }}</a>
                    @endforeach
                </x-slot:periods>
            </x-chart.trend>
        </div>
    @endisset

    {{-- A total is not an answer. See DashboardController::whoOwes(): one
         customer who has not paid is a phone call this afternoon, and twenty
         who each owe a little is how a shop works — and 222,000 looks the same
         either way. --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['label' => __('Customers owe the shop'), 'owed' => $customersOwe,
             'route' => 'customers.index', 'each' => 'customers.show'],
            ['label' => __('The shop owes suppliers'), 'owed' => $owedToSuppliers,
             'route' => 'suppliers.index', 'each' => 'suppliers.show'],
        ] as $balance)
            {{-- ⚠️ The card STAYS when the reader may not see it, masked.
                 Section 2 and SecurityTest: "a missing one says the shop has no
                 such figure; a masked one says there is one and it is not
                 theirs." A first version of this used @continue and deleted the
                 card outright — the suite caught it, correctly. --}}
            @php($owed = $balance['owed'])

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="text-secondary small">{{ $balance['label'] }}</div>
                                <div class="fs-5 fw-semibold money">
                                    {{ money_if($owed !== null, $owed['total'] ?? null, true, $lens) }}
                                </div>

                                @isset($owed)
                                    <div class="small text-secondary">
                                        {{ trans_choice('{0}Nobody|{1}:count account|[2,*]:count accounts',
                                            $owed['count'], ['count' => $owed['count']]) }}
                                    </div>
                                @endisset
                            </div>

                            {{-- Section 9b: never a link that leads to access denied. --}}
                            @isset($owed)
                                <a href="{{ route($balance['route']) }}" class="btn btn-sm btn-outline-secondary">
                                    {{ __('View') }}
                                </a>
                            @endisset
                        </div>

                        @if(($owed['top'] ?? []) !== [])
                            <ul class="list-unstyled small mb-0 mt-3 border-top pt-2">
                                @foreach($owed['top'] as $account)
                                    <li class="d-flex justify-content-between gap-2 py-1">
                                        <span class="text-truncate">{{ $account['name'] }}</span>
                                        <span class="money text-nowrap">{{ money($account['balance'], false, $lens) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($lowStock !== null || $recentSales !== null)
        <div class="row g-3">
            @if($lowStock !== null)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle text-warning"></i>
                            {{ __('Low stock') }}
                        </div>
                        @if($lowStock->isEmpty())
                            <x-empty-state icon="check-circle" :message="__('Nothing is running low.')" />
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Product') }}</th>
                                        <th class="money">{{ __('In stock') }}</th>
                                        <th class="money">{{ __('Reorder at') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($lowStock as $product)
                                        <tr>
                                            <td>
                                                <a href="{{ route('products.show', $product) }}"
                                                   class="text-decoration-none">{{ $product->name }}</a>
                                                <div class="small text-secondary">{{ $product->sku }}</div>
                                            </td>
                                            <td class="money fw-semibold">{{ number_format($product->quantity) }}</td>
                                            <td class="money text-secondary">{{ number_format($product->effectiveReorderLevel()) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if($recentSales !== null)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">{{ __('Recent sales') }}</div>
                        @if($recentSales->isEmpty())
                            <x-empty-state icon="receipt"
                                           :message="__('No sales yet. Create your first sale.')"
                                           :action="Gate::allows('sales.create') ? route('sales.create') : null"
                                           :action-label="__('New sale')" />
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>{{ __('Invoice') }}</th>
                                        <th>{{ __('Customer') }}</th>
                                        <th class="money">{{ __('Total') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($recentSales as $sale)
                                        <tr>
                                            <td>
                                                <a href="{{ route('sales.show', $sale) }}"
                                                   class="text-decoration-none">{{ $sale->document_no }}</a>
                                                @if($sale->status !== 'active')
                                                    <x-status-badge :status="$sale->status" />
                                                @endif
                                            </td>
                                            <td class="text-truncate">{{ $sale->customer->displayName() }}</td>
                                            <td class="money">{{ money($sale->total_amount, false, $lens) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
@endsection
