@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
    {{--
        Every panel is behind the permission of the screen it summarises — see
        DashboardController. A reader who holds none of them is not shown an
        empty shell; they are told plainly that this page has nothing for them.
    --}}
    @php
        $hasAnything = count($cards) > 0
            || $customersOwe !== null
            || $owedToSuppliers !== null
            || $lowStock !== null
            || $recentSales !== null;
    @endphp

    @if(! $hasAnything)
        <x-empty-state icon="speedometer2"
                       :message="__('Nothing to show here yet. Use the menu on the left.')" />
    @endif

    @if($cards)
        <div class="row g-3 mb-4">
            @foreach($cards as $card)
                <div class="col-6 col-xl-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 text-secondary small mb-1">
                                <i class="bi bi-{{ $card['icon'] }}"></i>{{ $card['label'] }}
                            </div>
                            <div class="fs-4 fw-semibold money">{{ money($card['value']) }}</div>
                            @if($card['note'])
                                <div class="small text-secondary">{{ $card['note'] }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($customersOwe !== null || $owedToSuppliers !== null)
        <div class="row g-3 mb-4">
            @if($customersOwe !== null)
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-secondary small">{{ __('Customers owe the shop') }}</div>
                                <div class="fs-5 fw-semibold money">{{ money($customersOwe) }}</div>
                            </div>
                            <a href="{{ route('customers.index') }}" class="btn btn-sm btn-outline-secondary">
                                {{ __('View') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endif
            @if($owedToSuppliers !== null)
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-secondary small">{{ __('The shop owes suppliers') }}</div>
                                <div class="fs-5 fw-semibold money">{{ money($owedToSuppliers) }}</div>
                            </div>
                            <a href="{{ route('suppliers.index') }}" class="btn btn-sm btn-outline-secondary">
                                {{ __('View') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

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
