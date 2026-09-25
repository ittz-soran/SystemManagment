@extends('layouts.app')

@section('title', $return->document_no)
@section('subheading')
    {{ $return->return_date->format(setting('date_format', 'Y-m-d')) }} · {{ $return->customer->displayName() }}
    · @can('sales.view')
        {{-- The number is the way back to the document this return came from. --}}
        {!! __('against :document', ['document' => '<a href="'.e(route('sales.show', $return->sale)).'" class="text-decoration-none" dir="ltr">'.e($return->sale->document_no).'</a>']) !!}
    @else
        {{ __('against :document', ['document' => $return->sale->document_no]) }}
    @endcan
@endsection

@section('actions')

    <a href="{{ route('sale-returns.print', $return) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

    @can('sale_returns.delete')
        {{-- Section 5: deleting a return is trivial and safe — its movements are
             subtracted from their batches and removed, and reverses_movement_id
             restores the earlier state exactly. That holds only while the units
             it put back are still in their batch, so the button reports why
             when they are not. --}}
        @if(! $deleteState['allowed'])
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled>
                    <i class="bi bi-trash me-1"></i>{{ __('Delete return') }}
                </button>
            </span>
        @else
        <form action="{{ route('sale-returns.destroy', $return) }}" method="POST"
              onsubmit="return confirm(@js(__('Delete :document? Stock will drop by :units units and the refund will be undone.', [
                  'document' => $return->document_no,
                  'units' => $return->items->sum('quantity'),
              ])))">
            @csrf
            @method('DELETE')
            <button class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete return') }}
            </button>
        </form>
        @endif
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('sale-returns.index')" :label="__('Sale returns')" remember="sale-returns" permission="sale_returns.view" />
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">{{ __('Returned items') }}</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="money">{{ __('Quantity') }}</th>
                            <th class="money">{{ __('Unit price') }}</th>
                            <th class="money">{{ __('Refund') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($return->items as $item)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $item->product) }}" class="text-decoration-none">
                                        {{ $item->product->name }}
                                    </a>
                                    <div class="small text-secondary" dir="ltr">{{ $item->product->sku }}</div>
                                </td>
                                <td class="money">{{ qty($item->quantity, $item->product->unit) }}</td>
                                <td class="money">{{ money($item->unit_price, false, $lens) }}</td>
                                <td class="money fw-semibold">{{ money($item->lineTotal(), false, $lens) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">{{ __('Total refund') }}</td>
                            <td class="money">{{ money($return->total_amount, false, $lens) }}</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                @if($return->reason)
                    <div class="card-footer small">
                        <span class="text-secondary">{{ __('Reason') }}:</span> {{ $return->reason }}
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            {{-- ⚠️ **The money, then where it went** — the same two cards as a
                 purchase return and a swap, Soran 2026-09-25. The total is in
                 the table's foot as well, and deliberately: what is new here is
                 the SPLIT, and a split with no total over it is a figure the
                 reader has to scroll back to make sense of. --}}
            @php
                $paidOut = (int) $payments->sum('amount');
                $offBalance = max(0, (int) $return->total_amount - $paidOut);
            @endphp
            <div class="card mb-3">
                <div class="card-header">{{ __('The money') }}</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">{{ __('Total refund') }}</span>
                        <span class="money">{{ money($return->total_amount, false, $lens) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary">{{ __('Cash out of the till') }}</span>
                        <span class="money">{{ money($paidOut, false, $lens) }}</span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between fw-semibold">
                        <span>{{ __('Came off what they owed') }}</span>
                        <span class="money">{{ money($offBalance, false, $lens) }}</span>
                    </div>

                    <div class="small text-secondary mt-2">
                        {{-- Section 7: a refund clears the debt before it opens
                             the till, so this is the ordinary case, not an
                             exception worth a warning. --}}
                        {{ $offBalance > 0
                            ? __('A refund clears what the customer owes first, and only what is left over leaves the till.')
                            : __('They owed nothing, so the whole refund left the till.') }}
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">{{ __('Cash paid back') }}</div>
                @if($payments->isEmpty())
                    <div class="card-body small text-secondary">
                        {{-- Section 7: a refund first clears what the customer owes. --}}
                        {{ __('The whole refund went against what the customer owed, so no cash left the till.') }}
                    </div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($payments as $payment)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="d-block small">
                                        <x-document-link :document="$payment" :kind="false" />
                                    </span>
                                    <span class="small text-secondary">{{ Str::headline($payment->payment_method) }}</span>
                                </span>
                                <span class="money text-danger">
                                    −{{ money($payment->amount, false, $lens) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
<x-record-history :for="$return" />
@endsection
