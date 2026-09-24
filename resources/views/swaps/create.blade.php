@extends('layouts.app')

@section('title', __('Swap a faulty item'))

@section('back')
    <x-back-link :to="route('swaps.index')" :label="__('Swaps')" remember="swaps" permission="swaps.view" />
@endsection

@section('actions')
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    {{-- ─── The line is chosen: what the shop can do about it ─────────────── --}}
    @if($line)
        @php
            $onShelf = (int) $product->quantity;
            $canComeBack = $line->returnableQuantity();
            $mostToSwap = min($canComeBack, $onShelf);
            $haveOne = $product->tracksStock() && $onShelf > 0;
        @endphp

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="fs-5 fw-semibold">{{ $product->name }}</div>
                        <div class="small text-secondary" dir="ltr">{{ $product->sku }}</div>
                    </div>
                    <a href="{{ route('swaps.create', ['product' => $product->id]) }}"
                       class="btn btn-sm btn-outline-secondary">{{ __('Choose another invoice') }}</a>
                </div>

                <hr>

                <div class="row g-3 small">
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('Sold on') }}</div>
                        <div><x-document-link :document="$line->sale" :kind="false" /></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('Customer') }}</div>
                        <div>{{ $line->sale->customer->displayName() }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('Date') }}</div>
                        <div class="app-code">{{ $line->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('They paid') }}</div>
                        <div class="money">{{ money($line->unit_price, false, $lens) }}</div>
                    </div>

                    {{-- ⚠️ The shelf is read HERE rather than beside the
                         buttons. On a phone the side column lands under them,
                         and the one number that decides which way out to take
                         would be the one below the fold. --}}
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('On the shelf right now') }}</div>
                        <div class="fw-semibold">{{ qty($onShelf, $product->unit) }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary">{{ __('Still to come back') }}</div>
                        <div class="fw-semibold">{{ number_format($canComeBack) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                {{-- Option one, and the only one that is a swap. --}}
                <div class="card mb-3">
                    <div class="card-header">{{ __('Hand over the same thing') }}</div>
                    <div class="card-body">
                        @if(! $product->tracksStock())
                            <div class="text-secondary">
                                {{ __('A service cannot be swapped — there is nothing to hand over.') }}
                            </div>
                        @elseif($canComeBack < 1)
                            <div class="text-secondary">
                                {{ __('Nothing is left on this line to swap — it has already come back.') }}
                            </div>
                        @elseif(! $haveOne)
                            {{-- The shelf is empty, so this option is not offered
                                 at all. The other two are still open, and the
                                 panel beside this one says so. --}}
                            <div class="d-flex align-items-start gap-2 text-secondary">
                                <i class="bi bi-x-circle mt-1"></i>
                                <div>
                                    {{ __('There is no :product left to swap it for. Return it or change it for something else.', ['product' => $product->name]) }}
                                </div>
                            </div>
                        @else
                            <p class="text-secondary small">
                                {{ __('The invoice is not changed. The customer bought it and still owns it — what changes is which unit they have, and what is on your shelf.') }}
                            </p>

                            <form action="{{ route('swaps.store') }}" method="POST" data-guard-submit>
                                @csrf
                                <input type="hidden" name="sale_item_id" value="{{ $line->id }}">

                                <div class="row g-3 align-items-end">
                                    <div class="col-6 col-md-4">
                                        <label for="quantity" class="form-label">{{ __('How many') }}</label>
                                        <div class="input-group">
                                            <input id="quantity" type="number" name="quantity" dir="ltr"
                                                   class="form-control text-end"
                                                   min="1" max="{{ $mostToSwap }}" step="1"
                                                   value="{{ old('quantity', 1) }}" required>
                                            @if($product->unit !== '')
                                                <span class="input-group-text">{{ $product->unit }}</span>
                                            @endif
                                        </div>
                                        <div class="form-text">
                                            {{ __('At most :count — what is left on the line, and what is on the shelf.', ['count' => number_format($mostToSwap)]) }}
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-8">
                                        <label for="note" class="form-label">{{ __('Note') }}</label>
                                        <input id="note" name="note" class="form-control" maxlength="500"
                                               value="{{ old('note') }}"
                                               placeholder="{{ __('Not charging, screen dead, dead on arrival…') }}">
                                    </div>
                                </div>

                                <div class="d-grid mt-3">
                                    <button type="submit" class="btn btn-primary btn-lg"
                                            data-submitting-text="{{ __('Saving…') }}">
                                        {{ __('Swap it') }}
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- The other two outcomes are one screen: the sale return
                     already refunds, already lets the till sell something else,
                     and already sends the faulty unit back to its supplier. --}}
                <div class="card">
                    <div class="card-header">{{ __('Something different, or the money back') }}</div>
                    <div class="card-body">
                        <p class="text-secondary small mb-3">
                            {{ __('Both of these change the invoice, because what the customer owns changes. Take the item back first — then sell the other product, or hand over the cash.') }}
                        </p>

                        @if($mayReturn && $canComeBack > 0)
                            <a class="btn btn-outline-primary"
                               href="{{ route('sale-returns.create', ['sale' => $line->sale, 'line' => $line->id]) }}">
                                <i class="bi bi-arrow-return-left me-1"></i>{{ __('Take it back on the invoice') }}
                            </a>
                        @elseif($canComeBack > 0)
                            <div class="text-secondary small">
                                {{ __('You are not allowed to take returns, so ask somebody who is.') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                {{-- Who carries the cost, answered before anything is done. --}}
                <div class="card">
                    <div class="card-header">{{ __('Where the faulty one came from') }}</div>
                    @php
                        $bought = $origins->filter(fn ($origin) => $origin->purchase !== null);
                        $orphans = $origins->filter(fn ($origin) => $origin->purchase === null);
                    @endphp

                    @if($bought->isEmpty() && $orphans->isEmpty())
                        <div class="card-body small text-secondary">{{ __('Nothing to trace.') }}</div>
                    @else
                        <ul class="list-group list-group-flush small">
                            @foreach($bought as $origin)
                                <li class="list-group-item">
                                    {{ trans_choice('{1}:count from|[2,*]:count from', $origin->quantity, ['count' => $origin->quantity]) }}
                                    @can('purchases.view')
                                        <a href="{{ route('purchases.show', $origin->purchase) }}" dir="ltr">{{ $origin->purchase->document_no }}</a>
                                    @else
                                        <span dir="ltr">{{ $origin->purchase->document_no }}</span>
                                    @endcan
                                    · {{ $origin->supplier->name }}
                                    <div class="text-secondary">
                                        {{ __('Goes back to them, and they give back :amount.', ['amount' => money($origin->quantity * $origin->unit_cost, false, $lens)]) }}
                                    </div>
                                </li>
                            @endforeach

                            @if($orphans->isNotEmpty())
                                <li class="list-group-item text-secondary">
                                    <i class="bi bi-info-circle me-1"></i>
                                    {{ trans_choice(
                                        '{1}:count unit did not come from a purchase, so there is no supplier to send it back to.'
                                        .'|[2,*]:count units did not come from a purchase, so there is no supplier to send them back to.',
                                        $orphans->sum('quantity'), ['count' => $orphans->sum('quantity')]) }}
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>
            </div>
        </div>

    {{-- ─── A product is chosen: which invoice sold it? ───────────────────── --}}
    @elseif($product)
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="fs-5 fw-semibold">{{ $product->name }}</div>
                    <div class="small text-secondary" dir="ltr">{{ $product->sku }}</div>
                    <div class="small text-secondary">
                        {{ __('On the shelf right now: :count', ['count' => qty($product->quantity, $product->unit)]) }}
                    </div>
                </div>
                <a href="{{ route('swaps.create') }}" class="btn btn-sm btn-outline-secondary">
                    {{ __('Choose another product') }}
                </a>
            </div>
        </div>

        @if($lines->isEmpty())
            <div class="card">
                <x-empty-state icon="receipt"
                               :message="__('No invoice has this product still to come back. Nothing was sold, or everything sold has already been returned or swapped.')"
                               :action="route('swaps.create')"
                               :action-label="__('Choose another product')" />
            </div>
        @else
            <div class="card">
                <div class="card-header">{{ __('Which invoice sold it?') }}</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-cards">
                        <thead>
                        <tr>
                            <th>{{ __('Document') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th class="money">{{ __('Sold') }}</th>
                            <th class="money">{{ __('Still to come back') }}</th>
                            <th class="money">{{ __('They paid') }}</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($lines as $item)
                            <tr>
                                <td class="list-card-title"><x-document-link :document="$item->sale" :kind="false" /></td>
                                <td data-label="{{ __('Date') }}">
                                    <span class="app-code">{{ $item->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</span>
                                </td>
                                <td data-label="{{ __('Customer') }}">{{ $item->sale->customer->displayName() }}</td>
                                <td class="money" data-label="{{ __('Sold') }}">{{ number_format($item->quantity) }}</td>
                                <td class="money fw-semibold" data-label="{{ __('Still to come back') }}">{{ number_format($item->returnableQuantity()) }}</td>
                                <td class="money" data-label="{{ __('They paid') }}">{{ money($item->unit_price, false, $lens) }}</td>
                                <td class="list-card-actions text-end">
                                    <a href="{{ route('swaps.create', ['sale_item' => $item->id]) }}"
                                       class="btn btn-sm btn-primary">{{ __('This one') }}</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    {{-- ─── Nothing chosen yet: find the product ──────────────────────────── --}}
    @else
        <div class="card mb-3">
            <div class="card-body">
                <p class="text-secondary small">
                    {{ __('Start with the faulty item in your hand: scan it, or type its name.') }}
                </p>

                <form method="GET" action="{{ route('swaps.create') }}" class="row g-2">
                    <div class="col-12 col-md-8">
                        <label for="q" class="visually-hidden">{{ __('Product') }}</label>
                        <input id="q" type="search" name="q" value="{{ $term }}" autofocus
                               class="form-control form-control-lg"
                               placeholder="{{ __('Name, SKU or barcode') }}">
                    </div>
                    <div class="col-12 col-md-4 d-grid">
                        <button class="btn btn-primary btn-lg">
                            <i class="bi bi-search me-1"></i>{{ __('Find it') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        @if($term !== '')
            @if($products->isEmpty())
                <div class="card">
                    <x-empty-state icon="search" :message="__('Nothing matches :term.', ['term' => $term])" />
                </div>
            @else
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-cards">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money">{{ __('On the shelf') }}</th>
                                <th class="text-end"></th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($products as $found)
                                <tr>
                                    <td class="list-card-title">
                                        {{ $found->name }}
                                        <div class="small text-secondary" dir="ltr">{{ $found->sku }}</div>
                                    </td>
                                    <td class="money" data-label="{{ __('On the shelf') }}">{{ qty($found->quantity, $found->unit) }}</td>
                                    <td class="list-card-actions text-end">
                                        <a href="{{ route('swaps.create', ['product' => $found->id]) }}"
                                           class="btn btn-sm btn-primary">{{ __('This one') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    @endif
@endsection
