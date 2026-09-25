@extends('layouts.app')

@section('title', __('Goods coming back'))

@section('back')
    @if($line || $bought)
        <x-back-link :to="route('goods-back.index', ['product' => $product->id])" :label="$product->name" />
    @elseif($product)
        <x-back-link :to="route('goods-back.index')" :label="__('Find the item')" />
    @endif
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    {{-- ─── Step 1: find the item ─────────────────────────────────────────── --}}
    @unless($product)
        <div class="card mb-3">
            <div class="card-body">
                {{-- The layout already prints the page's name from
                     @section('title'); a second h1 here would be the same
                     heading twice, once for the eye and once for a screen
                     reader. --}}
                <p class="text-secondary small mb-3">
                    {{ __('Swap it, take it back from the customer, or send it back to the supplier. Find the item first — the page then offers only what can really be done with it.') }}
                </p>

                <form method="GET" action="{{ route('goods-back.index') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-md-9">
                        <label for="gb-q" class="form-label">{{ __('Which item came back?') }}</label>
                        {{-- The suggestion panel is absolutely positioned, so it
                             needs a relative parent that is NOT the grid column:
                             a column carries the row gutter as padding, and a
                             w-100 panel inside one sits proud of its own box. --}}
                        <div class="position-relative">
                            <input id="gb-q" type="search" name="q" value="{{ $term }}"
                                   class="form-control form-control-lg" data-english-digits
                                   autocomplete="off" role="combobox"
                                   aria-expanded="false" aria-controls="gb-suggestions"
                                   placeholder="{{ __('Scan a barcode, or type a name or SKU') }}">
                            <div id="gb-suggestions" class="app-search-results dropdown-menu w-100 p-0 overflow-auto"
                                 role="listbox" aria-label="{{ __('Which item came back?') }}"
                                 data-url="{{ route('goods-back.suggest') }}"
                                 data-empty="{{ __('Nothing found.') }}"></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3 d-grid">
                        <button class="btn btn-primary btn-lg">
                            <i class="bi bi-search me-1"></i>{{ __('Find it') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        @if($term !== '')
            <div class="card">
                <div class="card-header">{{ __('Products') }}</div>
                @if($products->isEmpty())
                    <div class="card-body text-secondary small">
                        {{ __('Nothing matches :term.', ['term' => $term]) }}
                    </div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($products as $found)
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"
                               href="{{ route('goods-back.index', ['product' => $found->id]) }}">
                                <span class="min-w-0">
                                    <span class="d-block text-truncate">{{ $found->name }}</span>
                                    <span class="d-block small text-secondary" dir="ltr">{{ $found->sku }}</span>
                                </span>
                                <span class="small text-secondary text-nowrap">
                                    {{ trans_choice('{0}nothing on the shelf|[1,*]:count on the shelf', (int) $found->quantity, ['count' => number_format($found->quantity)]) }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endunless

    {{-- ─── Step 2: which paper is it on? ─────────────────────────────────── --}}
    @if($product && ! $line && ! $bought)
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="fs-5 fw-semibold">{{ $product->name }}</div>
                    <div class="small text-secondary" dir="ltr">{{ $product->sku }}</div>
                </div>
                <div class="text-end small text-secondary">
                    <div>{{ __('On the shelf right now') }}</div>
                    <div class="fs-5 text-body">{{ number_format($product->quantity) }} {{ $product->unit }}</div>
                </div>
            </div>
        </div>

        <p class="text-secondary small">
            {{ __('Pick the paper it is on. A sale return gives back that invoice line’s price; a supplier return comes off that purchase’s own batch — so the page has to know which one before it can offer anything.') }}
        </p>

        <div class="card mb-3">
            <div class="card-header">{{ __('Sold to a customer') }}</div>
            @if($soldLines->isEmpty())
                <div class="card-body text-secondary small">
                    {{ __('Never sold, or every line has already come back. Nothing can come back from a customer.') }}
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($soldLines as $sold)
                        <a class="list-group-item list-group-item-action"
                           href="{{ route('goods-back.index', ['sale_item' => $sold->id]) }}">
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div class="min-w-0">
                                    <div class="fw-semibold">
                                        <span class="app-code">{{ $sold->sale->document_no }}</span>
                                        · {{ $sold->sale->customer->displayName() }}
                                    </div>
                                    <div class="small text-secondary">
                                        <span class="app-code">{{ $sold->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</span>
                                        · {{ number_format($sold->quantity) }} {{ $product->unit }}
                                        · <span class="money">{{ money($sold->unit_price, false, $lens) }}</span>
                                    </div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <div class="money fw-semibold">{{ money($sold->quantity * $sold->unit_price, false, $lens) }}</div>
                                    <div class="small text-warning-emphasis">
                                        {{ __(':count can come back', ['count' => number_format($sold->returnableQuantity())]) }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="card">
            <div class="card-header">{{ __('Bought from a supplier') }}</div>
            @if($boughtLines->isEmpty())
                <div class="card-body text-secondary small">
                    {{ __('No purchase behind this one — opening stock, or carried in from another room. There is no supplier to send it back to.') }}
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($boughtLines as $buy)
                        @php $dead = $buy->can_go_back < 1; @endphp
                        <{{ $dead ? 'div' : 'a' }} class="list-group-item {{ $dead ? 'text-secondary' : 'list-group-item-action' }}"
                            @if(! $dead) href="{{ route('goods-back.index', ['purchase_item' => $buy->id]) }}" @endif>
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div class="min-w-0">
                                    <div class="fw-semibold">
                                        <span class="app-code">{{ $buy->purchase->document_no }}</span>
                                        · {{ $buy->purchase->supplier->name }}
                                    </div>
                                    <div class="small text-secondary">
                                        <span class="app-code">{{ $buy->purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }}</span>
                                        @if($buy->batch_no)
                                            · {{ __('batch') }} <span class="app-code">#{{ $buy->batch_no }}</span>
                                        @endif
                                        · {{ number_format($buy->quantity) }} {{ $product->unit }}
                                        · <span class="money">{{ money($buy->unit_price, false, $lens) }}</span>
                                    </div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <div class="money fw-semibold">{{ money($buy->quantity * $buy->unit_price, false, $lens) }}</div>
                                    <div class="small {{ $dead ? '' : 'text-success-emphasis' }}">
                                        @if($dead)
                                            {{ $buy->in_batch < 1
                                                ? __('that batch is empty — they have all been sold')
                                                : __('already sent back') }}
                                        @else
                                            {{ __(':count can go back', ['count' => number_format($buy->can_go_back)]) }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </{{ $dead ? 'div' : 'a' }}>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ─── Step 3a: a sold line — what does the customer want? ───────────── --}}
    @if($line)
        @include('goods-back.partials.sold')
    @endif

    {{-- ─── Step 3b: a bought line — back to the supplier ─────────────────── --}}
    @if($bought)
        @include('goods-back.partials.bought')
    @endif
@endsection
