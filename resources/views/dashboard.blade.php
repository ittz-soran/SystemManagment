@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
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
                        <div class="d-flex align-items-center gap-2 text-secondary small mb-1">
                            <i class="bi bi-{{ $card['icon'] }}"></i>{{ $card['label'] }}
                        </div>
                        <div class="fs-4 fw-semibold money">
                            {{ $card['cost'] ? cost_money($card['value']) : money_if($card['value'] !== null, $card['value']) }}
                        </div>
                        @if($card['note'])
                            <div class="small text-secondary">{{ $card['note'] }}</div>
                        @endif

                        {{-- The shape of the last four weeks behind the figure:
                             the tile says what today was, this says whether
                             today was normal. Absent, not empty, when the
                             reader may not see the figure — see the controller. --}}
                        @if(($card['spark'] ?? null) !== null)
                            <x-chart.spark :values="$card['spark']" />
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
            <x-chart.trend
                :title="__('The last four weeks')"
                :subtitle="__('Hover any day to read every line at once.')"
                :labels="$trend['labels']"
                :notes="$trend['notes']"
                :series="$trend['series']"
                :level="$trend['level']"
                :height="200" />
        </div>
    @endisset

    <div class="row g-3 mb-4">
        @foreach([
            ['label' => __('Customers owe the shop'), 'value' => $customersOwe, 'route' => 'customers.index'],
            ['label' => __('The shop owes suppliers'), 'value' => $owedToSuppliers, 'route' => 'suppliers.index'],
        ] as $balance)
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-secondary small">{{ $balance['label'] }}</div>
                            <div class="fs-5 fw-semibold money">
                                {{ money_if($balance['value'] !== null, $balance['value']) }}
                            </div>
                        </div>
                        {{-- Section 9b: never a link that leads to access denied. --}}
                        @if($balance['value'] !== null)
                            <a href="{{ route($balance['route']) }}" class="btn btn-sm btn-outline-secondary">
                                {{ __('View') }}
                            </a>
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
                                            <td class="money">{{ money($sale->total_amount, false) }}</td>
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
