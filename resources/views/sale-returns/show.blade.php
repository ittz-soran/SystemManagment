@extends('layouts.app')

@section('title', $return->document_no)
@section('subheading')
    {{ $return->return_date->format(setting('date_format', 'Y-m-d')) }} · {{ $return->customer->name }}
    · {{ __('against :document', ['document' => $return->sale->document_no]) }}
@endsection

@section('actions')
    <a href="{{ route('sale-returns.print', $return) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

    @can('sale_returns.delete')
        {{-- Section 5: deleting a return is trivial and safe — its movements are
             subtracted from their batches and removed, and reverses_movement_id
             restores the earlier state exactly. --}}
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
    @endcan
@endsection

@section('content')
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
                                <td class="money">{{ number_format($item->quantity) }}</td>
                                <td class="money">{{ money($item->unit_price, false) }}</td>
                                <td class="money fw-semibold">{{ money($item->lineTotal(), false) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="3" class="text-end">{{ __('Total refund') }}</td>
                            <td class="money">{{ money($return->total_amount, false) }}</td>
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
                                    <span class="d-block small" dir="ltr">{{ $payment->document_no }}</span>
                                    <span class="small text-secondary">{{ Str::headline($payment->payment_method) }}</span>
                                </span>
                                <span class="money text-danger">
                                    −{{ money($payment->amount, false) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
