@extends('layouts.app')

@section('title', $product->name)
@section('subheading')
    <span dir="ltr">{{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif</span>
    · {{ $product->category->name }}
    {{-- Skipped when the category already says it, which it does by default.
         Compared against the stored name, which is not translated. --}}
    @if($product->isUsed() && $product->category->name !== App\Services\SecondHandService::DEFAULT_CATEGORY)
        · <span class="badge text-bg-light">{{ __('Second-hand') }}</span>
    @elseif($product->isService() && $product->category->name !== App\Http\Controllers\ServiceController::DEFAULT_CATEGORY)
        · <span class="badge text-bg-light">{{ __('Service') }}</span>
    @endif
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

    {{-- Correcting what is on the shelf belongs next to the product it is
         about, not three screens away. A service holds no stock, so there is
         nothing about it to adjust. --}}
    @can('stock_adjustments.create')
        @unless($product->isService())
            <a href="{{ route('stock-adjustments.index', ['product' => $product->id]) }}"
               class="btn btn-outline-secondary">
                <i class="bi bi-sliders me-1"></i>{{ __('New adjustment') }}
            </a>
        @endunless
    @endcan

    @can('products.edit')
        <a href="{{ route('products.edit', $product) }}" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </a>
    @endcan

    {{-- Section 5: a product with stock history is deactivated, never deleted —
         its movements are somebody's invoice. The button says so before it is
         pressed rather than surprising the reader afterwards. --}}
    @can('products.delete')
        <form action="{{ route('products.destroy', $product) }}" method="POST" class="d-inline"
              onsubmit="return confirm(@js(__('Delete :name? Products with stock history are deactivated instead.', ['name' => $product->name])))">
            @csrf
            @method('DELETE')
            <x-return-to />
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
            </button>
        </form>
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

    {{-- A one-off second-hand item has no level at which to reorder it, and a
         service has no stock behind any of these but its price. --}}
    @php
        $cards = $product->isService()
            ? [['label' => __('Sale price'), 'value' => money($product->sale_price)]]
            : [
                ['label' => __('In stock'), 'value' => number_format($product->quantity).' '.$product->unit],
                // A total beside a count is a unit cost one division away —
                // 100,000 over 10 pcs is 10,000 each — so it follows the same
                // rule as every other cost on the page.
                ['label' => __('Stock value'), 'value' => cost_money($stockValue)],
                ['label' => __('Sale price'), 'value' => money($product->sale_price)],
            ];

        if ($product->kind === App\Models\Product::KIND_STOCK) {
            $cards[] = ['label' => __('Reorder level'), 'value' => number_format($product->effectiveReorderLevel())];
        }
    @endphp

    <div class="row g-3 mb-4">
        @foreach($cards as $card)
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

    {{-- A second-hand item is one physical thing, so the questions worth asking
         of it are about that thing: who it came from, what was paid for it, how
         long it has been sitting here. --}}
    @if($product->isUsed())
        <div class="card mb-4">
            <div class="card-header">{{ __('Second-hand') }}</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    @if($product->condition_note)
                        <dt class="col-sm-3 text-secondary fw-normal">{{ __('Condition') }}</dt>
                        <dd class="col-sm-9">{{ $product->condition_note }}</dd>
                    @endif

                    @if($product->acquiredFrom)
                        <dt class="col-sm-3 text-secondary fw-normal">{{ __('Bought from') }}</dt>
                        <dd class="col-sm-9">
                            <x-document-link :document="$product->acquiredFrom" :kind="false" />
                            @if($product->acquiredFrom->phone)
                                <span class="text-secondary" dir="ltr">· {{ $product->acquiredFrom->phone }}</span>
                            @endif
                        </dd>
                    @endif

                    {{-- The batch, not the product row: the batch is the money
                         that actually left the till and the cost FIFO charges
                         the sale. --}}
                    <dt class="col-sm-3 text-secondary fw-normal">{{ __('Paid for it') }}</dt>
                    <dd class="col-sm-9 money">
                        {{ cost_money((int) ($batches->first()->unit_cost ?? $product->purchase_price), false) }}
                    </dd>

                    <dt class="col-sm-3 text-secondary fw-normal">{{ __('Status') }}</dt>
                    <dd class="col-sm-9 mb-0">
                        @if($product->isSold())
                            <span class="badge text-bg-secondary">{{ __('Sold') }}</span>
                        @else
                            <span class="badge text-bg-success">{{ __('In stock') }}</span>
                            <span class="text-secondary">
                                {{ trans_choice('{0}bought today|{1}held :count day|[2,*]held :count days',
                                    (int) $product->created_at->diffInDays(now()),
                                    ['count' => number_format((int) $product->created_at->diffInDays(now()))]) }}
                            </span>
                        @endif
                    </dd>
                </dl>
            </div>

            {{-- Its whole life: the document it came in on and the one it left
                 on, with the money on each. Two lines, and between them the
                 profit on this one thing. --}}
            <ul class="list-group list-group-flush border-top">
                @if($boughtOn)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-arrow-down-left text-success"></i>
                            <span class="text-secondary small" dir="ltr">
                                {{ $boughtOn->purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }}
                            </span>
                            <x-document-link :document="$boughtOn->purchase" :kind="false" />
                        </span>
                        <span class="money">{{ cost_money($boughtOn->unit_price, false) }}</span>
                    </li>
                @endif

                @if($soldOn)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-arrow-up-right text-danger"></i>
                            <span class="text-secondary small" dir="ltr">
                                {{ $soldOn->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}
                            </span>
                            <x-document-link :document="$soldOn->sale" :kind="false" />
                        </span>
                        <span class="money">{{ money($soldOn->unit_price, false) }}</span>
                    </li>

                    @php($cost = cost_seen((int) ($batches->first()->unit_cost ?? $product->purchase_price)))
                    @php($profit = $cost === null ? null : $soldOn->unit_price - $cost)
                    <li class="list-group-item d-flex justify-content-between align-items-center fw-semibold">
                        <span>{{ __('Profit') }}</span>
                        @if($profit === null)
                            <span class="money text-secondary">{{ hidden_money() }}</span>
                        @else
                            <span class="money {{ $profit >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $profit >= 0 ? '+' : '−' }}{{ money(abs($profit), false) }}
                            </span>
                        @endif
                    </li>
                @endif
            </ul>
        </div>
    @endif

    {{-- A service has no batches and no movements: nothing was ever bought for
         it, so there is nothing to draw down. Saying so beats two empty tables
         that look like something has gone wrong. --}}
    @if($product->isService())
        <div class="card mb-4">
            <div class="card-body text-secondary small mb-0">
                {{ __('A service holds no stock. Nothing is bought for it and nothing is consumed, so it has no batches and no cost — everything it is sold for is profit.') }}
            </div>
        </div>
    @else
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
                            <td class="money">{{ cost_money($batch->unit_cost, false) }}</td>
                            <td class="money text-secondary">{{ number_format($batch->quantity_in) }}</td>
                            <td class="money fw-semibold">{{ number_format($batch->quantity_remaining) }}</td>
                            <td class="money">{{ cost_money($batch->quantity_remaining * $batch->unit_cost, false) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="4"></td>
                        <td class="money">{{ number_format($batchSum) }}</td>
                        <td class="money">{{ cost_money($stockValue, false) }}</td>
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
                            <td class="money text-secondary">{{ cost_money($movement->unit_cost, false) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @endif

    {{--
        Who touched this product, when, and what it was before.

        Section 4 already records it — activity_logs holds every create, edit
        and delete with the previous version of whatever changed — and until now
        the only way to read it was the whole shop's log, in one list, with this
        product's entries somewhere in it. Here it is the product's own page,
        which is where somebody stands when they ask why the price is what it is.

        The stock movements above say what happened to the shelf. This says what
        happened to the record.
    --}}
    @if($history !== null)
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-clock-history"></i>
                {{ __('History') }}
            </div>

            @if($history === [])
                <x-empty-state icon="clock-history"
                               :message="__('Nothing recorded yet. Changes from here on are kept.')" />
            @else
                <ul class="list-group list-group-flush">
                    @foreach($history as $entry)
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap align-items-baseline gap-2">
                                <span class="badge {{ match($entry['action']) {
                                    'create' => 'text-bg-success',
                                    'delete' => 'text-bg-danger',
                                    'restore' => 'text-bg-info',
                                    default => 'text-bg-secondary',
                                } }}">
                                    {{ match($entry['action']) {
                                        'create' => __('Created'),
                                        'update' => __('Edited'),
                                        'delete' => __('Deleted'),
                                        'restore' => __('Brought back'),
                                        default => Str::headline($entry['action']),
                                    } }}
                                </span>

                                <span class="fw-medium">{{ $entry['by'] }}</span>

                                <span class="text-secondary small" dir="ltr">
                                    {{ $entry['at']->format(setting('date_format', 'Y-m-d')) }}
                                    {{ $entry['at']->format('H:i') }}
                                </span>

                                @if($entry['ip'])
                                    <span class="text-secondary small" dir="ltr">· {{ $entry['ip'] }}</span>
                                @endif
                            </div>

                            @if($entry['changes'])
                                <div class="small mt-2">
                                    @foreach($entry['changes'] as $change)
                                        <div>
                                            <span class="text-secondary">{{ $change['label'] }}</span>
                                            <span class="text-decoration-line-through text-secondary ms-1">{{ $change['from'] }}</span>
                                            <i class="bi bi-arrow-right text-secondary mx-1"></i>
                                            <span class="fw-medium">{{ $change['to'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if($product->barcode)
        <x-label-modal :product="$product" :sizes="$labelSizes" :fields="$labelFields"
                       :printer="$labelPrinter" />
    @endif
@endsection
