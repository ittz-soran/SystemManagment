@extends('layouts.app')

@section('title', $transfer->document_no)

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-secondary small">{{ __('Number') }}</div>
                    <div class="app-code fw-semibold">{{ $transfer->document_no }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">{{ __('When') }}</div>
                    <div class="app-code">{{ $transfer->transferred_at->format('Y-m-d H:i') }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">{{ __('From') }}</div>
                    <div>{{ $transfer->fromRoom->name }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">{{ __('To') }}</div>
                    <div>{{ $transfer->toRoom->name }}</div>
                </div>
            </div>

            @if($transfer->note)
                <div class="mt-3 small text-secondary">{{ $transfer->note }}</div>
            @endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-cards align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="text-end">{{ __('Moved') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($transfer->items as $item)
                    <tr>
                        <td data-label="{{ __('Product') }}">
                            <a href="{{ route('products.show', $item->product_id) }}" class="text-decoration-none">
                                {{ $item->product->name }}
                            </a>
                            <div class="small text-secondary app-code">{{ $item->product->sku }}</div>
                        </td>
                        <td class="text-end" data-label="{{ __('Moved') }}">{{ qty($item->quantity, $item->product->unit) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-light border small">
        {{ __('Moving stock between rooms costs nothing and changes no price. The shop owns exactly as much after this as before.') }}
    </div>

    @can('stock_rooms.transfer')
        <form method="POST" action="{{ route('stock-transfers.destroy', $transfer) }}"
              onsubmit="return confirm(@json(__('Put this stock back where it came from?')))">
            @csrf
            @method('DELETE')
            <button class="btn btn-outline-danger">{{ __('Undo this move') }}</button>
        </form>
    @endcan
@endsection
