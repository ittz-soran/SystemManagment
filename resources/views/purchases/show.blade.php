@extends('layouts.app')

@section('title', $purchase->document_no)
@section('subheading')
    {{ $purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }} · {{ $purchase->supplier->name }}
@endsection

@section('actions')
    <a href="{{ route('purchases.print', $purchase) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

    @can('payments.create')
        <a href="{{ route('payments.create', ['payable_type' => 'purchase', 'payable_id' => $purchase->id]) }}"
           class="btn btn-outline-primary">
            <i class="bi bi-cash-coin me-1"></i>{{ __('Record payment') }}
        </a>
    @endcan

    @can('purchase_returns.create')
        @if($purchase->status !== 'returned')
            <a href="{{ route('purchase-returns.create', $purchase) }}" class="btn btn-primary">
                <i class="bi bi-arrow-return-left me-1"></i>{{ __('Return to supplier') }}
            </a>
        @endif
    @endcan

    {{-- Delete carries its own state: a purchase whose stock has been sold can
         still be corrected, but not removed. --}}
    <x-record-actions
        :state="$lockState"
        :delete-state="$deleteState"
        :edit="route('purchases.edit', $purchase)"
        :delete="route('purchases.destroy', $purchase)"
        :delete-label="__('Delete this purchase? Its batches are removed from stock.')" />
@endsection

@section('content')
    <x-lock-banner :state="$lockState" />

    @if($lockState['allowed'] && ! $deleteState['allowed'])
        {{-- Section 8: "This purchase has been used in a sale. You can edit it,
             but not delete it." --}}
        <div class="alert alert-secondary d-flex align-items-center gap-2 py-2">
            <i class="bi bi-info-circle"></i>
            <span>{{ $deleteState['reason'] }}</span>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>{{ __('Items') }}</span>
                    <x-status-badge :status="$purchase->status" />
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
                        @foreach($purchase->items as $item)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $item->product) }}" class="text-decoration-none">
                                        {{ $item->product->name }}
                                    </a>
                                    <div class="small text-secondary" dir="ltr">
                                        {{ $item->product->sku }}
                                        @if($item->entered_currency === 'USD' && $item->entered_amount)
                                            · {{ __('entered as $:amount', ['amount' => number_format($item->entered_amount / 100, 2)]) }}
                                        @endif
                                    </div>
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
                        <tr>
                            <td colspan="4" class="text-end text-secondary">{{ __('Subtotal') }}</td>
                            <td class="money">{{ money($purchase->total_amount, false) }}</td>
                        </tr>
                        @if($purchase->discount_amount !== 0)
                            <tr>
                                <td colspan="4" class="text-end text-secondary">
                                    {{ __('Discount') }}
                                    <span class="small">{{ __('(never applied to batch costs)') }}</span>
                                </td>
                                <td class="money">−{{ money($purchase->discount_amount, false) }}</td>
                            </tr>
                        @endif
                        <tr class="fw-semibold">
                            <td colspan="4" class="text-end">{{ __('Grand total') }}</td>
                            <td class="money">{{ money($purchase->grand_total, false) }}</td>
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
                                    <span class="d-block small" dir="ltr">{{ $payment->document_no }}</span>
                                    <span class="small text-secondary">
                                        {{ $payment->paid_at->format(setting('date_format', 'Y-m-d')) }}
                                        · {{ Str::headline($payment->payment_method) }}
                                    </span>
                                </span>
                                <span class="money">{{ money($payment->amount, false) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="card-footer d-flex justify-content-between fw-semibold">
                    <span>{{ __('Due') }}</span>
                    <span class="money">{{ money($purchase->amountDue()) }}</span>
                </div>
            </div>

            @if($purchase->returns->isNotEmpty())
                <div class="card">
                    <div class="card-header">{{ __('Returns') }}</div>
                    <ul class="list-group list-group-flush">
                        @foreach($purchase->returns as $return)
                            <li class="list-group-item d-flex justify-content-between">
                                <span dir="ltr">{{ $return->document_no }}</span>
                                <span class="money">{{ money($return->total_amount, false) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
