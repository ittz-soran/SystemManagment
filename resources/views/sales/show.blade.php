@extends('layouts.app')

@section('title', $sale->document_no)
@section('subheading')
    {{ $sale->sale_date->format(setting('date_format', 'Y-m-d')) }} · {{ $sale->customer->displayName() }}
@endsection

@section('actions')
    <a href="{{ route('sales.print', $sale) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

    @can('payments.create')
        <a href="{{ route('payments.create', ['payable_type' => 'sale', 'payable_id' => $sale->id]) }}"
           class="btn btn-outline-primary">
            <i class="bi bi-cash-coin me-1"></i>{{ __('Record payment') }}
        </a>
    @endcan

    @can('sale_returns.create')
        {{-- Section 7: make RETURN the obvious action on the sale page; keep
             edit tucked away. Returns are never blocked by the edit lock. --}}
        @if($sale->status !== 'returned')
            <a href="{{ route('sale-returns.create', $sale) }}" class="btn btn-primary">
                <i class="bi bi-arrow-return-left me-1"></i>{{ __('Return items') }}
            </a>
        @endif
    @endcan

    {{-- Section 9b: edit and delete are always shown — disabled with the lock
         reason as a tooltip — so it never looks as though they do not exist. --}}
    <x-record-actions
        :state="$lockState"
        :edit="route('sales.edit', $sale)"
        :delete="route('sales.destroy', $sale)"
        :delete-label="__('Delete this sale? Its stock goes back into the batches it came from.')" />
@endsection

@section('back')
    <x-back-link :to="route('sales.index')" :label="__('Sales history')" remember="sales" permission="sales.view" />
@endsection

@section('content')
    {{-- Section 9b: header, lines, totals, payments, timeline, actions. --}}
    <x-lock-banner :state="$lockState" />

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ __('Items') }}</span>
                    <x-status-badge :status="$sale->status" />
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="money">{{ __('Quantity') }}</th>
                            <th class="money">{{ __('Returned') }}</th>
                            <th class="money">{{ __('Unit price') }}</th>
                            <th class="money">{{ __('Total') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($sale->items as $item)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $item->product) }}" class="text-decoration-none">
                                        {{ $item->product->name }}
                                    </a>
                                    <div class="small text-secondary" dir="ltr">{{ $item->product->sku }}</div>
                                </td>
                                <td class="money">{{ number_format($item->quantity) }}</td>
                                <td class="money {{ $item->quantity_returned > 0 ? 'text-warning' : 'text-secondary' }}">
                                    {{ number_format($item->quantity_returned) }}
                                </td>
                                <td class="money">{{ money($item->unit_price, false) }}</td>
                                <td class="money fw-semibold">{{ money($item->lineTotal(), false) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="4" class="text-end">{{ __('Total') }}</td>
                            <td class="money">{{ money($sale->total_amount, false) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header">{{ __('Payments') }}</div>
                @if($payments->isEmpty())
                    <div class="card-body text-secondary small">{{ __('Nothing paid yet.') }}</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($payments as $payment)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="d-block small">
                                        <x-document-link :document="$payment" :kind="false" />
                                    </span>
                                    <span class="small text-secondary">
                                        {{ $payment->paid_at->format(setting('date_format', 'Y-m-d')) }}
                                        · {{ Str::headline($payment->payment_method) }}
                                    </span>
                                </span>
                                <span class="money {{ $payment->direction === 'in' ? 'text-success' : 'text-danger' }}">
                                    {{ $payment->direction === 'in' ? '+' : '−' }}{{ money($payment->amount, false) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="card-footer d-flex justify-content-between fw-semibold">
                    <span>{{ __('Due') }}</span>
                    <span class="money">{{ money($sale->amountDue()) }}</span>
                </div>
            </div>

            @if($sale->returns->isNotEmpty())
                <div class="card">
                    <div class="card-header">{{ __('Returns') }}</div>
                    <ul class="list-group list-group-flush">
                        @foreach($sale->returns as $return)
                            <li class="list-group-item d-flex justify-content-between">
                                @can('sale_returns.view')
                                    <a href="{{ route('sale-returns.show', $return) }}" class="text-decoration-none" dir="ltr">
                                        {{ $return->document_no }}
                                    </a>
                                @else
                                    <span dir="ltr">{{ $return->document_no }}</span>
                                @endcan
                                <span class="money">{{ money($return->total_amount, false) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
