@extends('layouts.app')

@section('title', $product->name)
@section('subheading')
    <span dir="ltr">{{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif</span>
    · {{ $product->category->name }}
@endsection

@section('actions')
    {{-- Section 4: a generated barcode is never printed on the goods, so the
         shop prints its own label. --}}
    @if($product->barcode)
        <button type="button" class="btn btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#label-modal">
            <i class="bi bi-upc-scan me-1"></i>{{ __('Print barcode') }}
        </button>
    @endif

    @can('products.edit')
        <a href="{{ route('products.edit', $product) }}" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </a>
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('products.index')" :label="__('Products')" remember="products" permission="products.view" />
@endsection

@section('content')
    @php
        $batchSum = (int) $product->stockBatches()->sum('quantity_remaining');
        $movementSum = (int) $product->stockMovements()->sum('quantity');
        $stockValue = $batches->sum(fn ($b) => $b->quantity_remaining * $b->unit_cost);
    @endphp

    {{-- Section 4: products.quantity is a cache. If it ever disagrees with the
         batches, the batches win — so say so plainly rather than hiding it. --}}
    @if($product->quantity !== $batchSum || $batchSum !== $movementSum)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            {{ __('Stock cache mismatch: the product row says :cached, the batches say :batches, and the movements say :movements. The batches are the truth.', [
                'cached' => number_format($product->quantity),
                'batches' => number_format($batchSum),
                'movements' => number_format($movementSum),
            ]) }}
        </div>
    @endif

    <div class="row g-3 mb-4">
        @foreach([
            ['label' => __('In stock'), 'value' => number_format($product->quantity).' '.$product->unit],
            ['label' => __('Stock value'), 'value' => money($stockValue)],
            ['label' => __('Sale price'), 'value' => money($product->sale_price)],
            ['label' => __('Reorder level'), 'value' => number_format($product->effectiveReorderLevel())],
        ] as $card)
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">{{ $card['label'] }}</div>
                        <div class="fs-5 fw-semibold money">{{ $card['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>{{ __('Stock batches (FIFO order)') }}</span>
            <span class="small text-secondary">{{ __('Oldest is consumed first') }}</span>
        </div>

        @if($batches->isEmpty())
            <x-empty-state icon="layers" :message="__('No stock batches yet. A purchase or an incoming adjustment creates the first one.')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Received') }}</th>
                        <th>{{ __('Source') }}</th>
                        <th class="money">{{ __('Cost each') }}</th>
                        <th class="money">{{ __('In') }}</th>
                        <th class="money">{{ __('Remaining') }}</th>
                        <th class="money">{{ __('Value') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($batches as $batch)
                        {{-- Section 5: "A batch that reaches 0 is not finished —
                             a return can refill it." So an empty batch is dimmed,
                             never hidden. --}}
                        <tr class="{{ $batch->quantity_remaining === 0 ? 'opacity-50' : '' }}">
                            <td class="small" dir="ltr">{{ $batch->received_at->format('Y-m-d H:i') }}</td>
                            <td class="small">
                                <x-document-link :document="$batch->source"
                                                 :type="$batch->source_type"
                                                 :id="$batch->source_id" />
                            </td>
                            <td class="money">{{ money($batch->unit_cost, false) }}</td>
                            <td class="money text-secondary">{{ number_format($batch->quantity_in) }}</td>
                            <td class="money fw-semibold">{{ number_format($batch->quantity_remaining) }}</td>
                            <td class="money">{{ money($batch->quantity_remaining * $batch->unit_cost, false) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="4"></td>
                        <td class="money">{{ number_format($batchSum) }}</td>
                        <td class="money">{{ money($stockValue, false) }}</td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header">{{ __('Stock movements') }}</div>

        @if($movements->isEmpty())
            <x-empty-state icon="arrow-left-right" :message="__('Nothing has moved yet.')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Batch') }}</th>
                        <th class="money">{{ __('Quantity') }}</th>
                        <th class="money">{{ __('Cost each') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($movements as $movement)
                        <tr>
                            <td class="small" dir="ltr">{{ $movement->occurred_at->format('Y-m-d H:i') }}</td>
                            <td class="small">
                                <x-document-link :document="$movement->reference"
                                                 :type="$movement->reference_type"
                                                 :id="$movement->reference_id" />
                            </td>
                            <td class="small text-secondary">#{{ $movement->stock_batch_id }}</td>
                            <td class="money fw-semibold {{ $movement->quantity > 0 ? 'text-success' : 'text-danger' }}">
                                {{ $movement->quantity > 0 ? '+' : '' }}{{ number_format($movement->quantity) }}
                            </td>
                            <td class="money text-secondary">{{ money($movement->unit_cost, false) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($product->barcode)
        <x-label-modal :product="$product" :sizes="$labelSizes" :fields="$labelFields"
                       :printer="$labelPrinter" />
    @endif
@endsection
