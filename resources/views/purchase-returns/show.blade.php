@extends('layouts.app')

@section('title', $return->document_no)
@section('subheading')
    {{ $return->return_date->format(setting('date_format', 'Y-m-d')) }} · {{ $return->supplier->name }}
    · @can('purchases.view')
        {{-- The number is the way back to the document this return came from. --}}
        {!! __('against :document', ['document' => '<a href="'.e(route('purchases.show', $return->purchase)).'" class="text-decoration-none" dir="ltr">'.e($return->purchase->document_no).'</a>']) !!}
    @else
        {{ __('against :document', ['document' => $return->purchase->document_no]) }}
    @endcan
@endsection

@section('actions')
    <a href="{{ route('purchase-returns.print', $return) }}" class="btn btn-outline-secondary" target="_blank">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </a>

    @can('purchase_returns.delete')
        {{-- Section 9b: never hidden. Disabled with the reason as its tooltip,
             so it is obvious the feature exists and why it cannot be used. --}}
        @if(! $deleteState['allowed'])
            <span class="d-inline-block" data-bs-toggle="tooltip" title="{{ $deleteState['reason'] }}">
                <button class="btn btn-outline-danger" disabled>
                    <i class="bi bi-trash me-1"></i>{{ __('Delete return') }}
                </button>
            </span>
        @else
            <form action="{{ route('purchase-returns.destroy', $return) }}" method="POST"
                  onsubmit="return confirm(@js(__('Delete :document? :units units go back into stock and the supplier credit is undone.', [
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
                            <th class="money">{{ __('Credit each') }}</th>
                            <th class="money">{{ __('Discount share') }}</th>
                            <th class="money">{{ __('Credit') }}</th>
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
                                {{-- Section 7: 0 means the supplier credited the
                                     full listed price, which is the default. --}}
                                <td class="money text-secondary">
                                    {{ $item->discount_share > 0 ? '−'.money($item->discount_share, false) : '—' }}
                                </td>
                                <td class="money fw-semibold">{{ money($item->creditTotal(), false) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr class="fw-semibold">
                            <td colspan="4" class="text-end">{{ __('Total credit') }}</td>
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
                <div class="card-header">{{ __('Cash received back') }}</div>
                @if($payments->isEmpty())
                    <div class="card-body small text-secondary">
                        {{ __('The whole credit went against what you owed this supplier, so no cash came back.') }}
                    </div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($payments as $payment)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="d-block small" dir="ltr">{{ $payment->document_no }}</span>
                                    <span class="small text-secondary">{{ Str::headline($payment->payment_method) }}</span>
                                </span>
                                <span class="money text-success">+{{ money($payment->amount, false) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
