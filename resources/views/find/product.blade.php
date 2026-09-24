{{--
    Everything the shop knows about one product, and what it can do about it.

    ⚠️ Not a second product page. That one is the record — batches, movements,
    ninety days of trend. This answers the question asked with a customer
    standing at the counter holding the thing: what do I know, and what now.
--}}
@php
    $tracks = $product->tracksStock();
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="fs-4 fw-semibold">{{ $product->name }}</div>
                <div class="small text-secondary">
                    <span class="app-code">{{ $product->sku }}</span>
                    @if($product->barcode)
                        · <span class="app-code">{{ $product->barcode }}</span>
                    @endif
                    @if($product->category)
                        · {{ $product->category->name }}
                    @endif
                </div>
            </div>
            <a href="{{ route('find') }}" class="btn btn-sm btn-outline-secondary">{{ __('Find something else') }}</a>
        </div>

        <hr>

        <div class="row g-3 small">
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('Price') }}</div>
                {{-- ⚠️ Not `.money`. These four cells are facts in a row, not a
                     column of figures to compare, and right-aligning one of
                     them put its number a half-column away from its own label
                     while the three beside it sat under theirs. --}}
                <div class="fw-semibold">{{ money($product->sale_price, false, $lens) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('On the shelf') }}</div>
                <div class="fw-semibold">{{ $tracks ? qty($product->quantity, $product->unit) : '—' }}</div>
            </div>
            @if($sold)
                <div class="col-6 col-md-3">
                    <div class="text-secondary">{{ __('Sold, all time') }}</div>
                    <div class="fw-semibold">{{ qty($sold['units'], $product->unit) }}</div>
                </div>
            @endif
            @if($bought)
                <div class="col-6 col-md-3">
                    <div class="text-secondary">{{ __('Bought, all time') }}</div>
                    <div class="fw-semibold">{{ qty($bought['units'], $product->unit) }}</div>
                </div>
            @endif
        </div>

        {{-- What can be done about it, from here. Each button is the permission
             of the screen it opens, so nobody is offered a door that answers
             "access denied". --}}
        <div class="d-flex flex-wrap gap-2 mt-3">
            @can('sales.create')
                <a href="{{ route('sales.create') }}" class="btn btn-primary">
                    <i class="bi bi-cart-plus me-1"></i>{{ __('Sell one') }}
                </a>
            @endcan
            @can('purchases.create')
                <a href="{{ route('purchases.create') }}" class="btn btn-outline-primary">
                    <i class="bi bi-bag-plus me-1"></i>{{ __('Buy more') }}
                </a>
            @endcan
            @can('swaps.create')
                @if($tracks)
                    <a href="{{ route('swaps.create', ['product' => $product->id]) }}" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left-right me-1"></i>{{ __('A faulty one came back') }}
                    </a>
                @endif
            @endcan
            <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">
                <i class="bi bi-box-seam me-1"></i>{{ __('Open the product page') }}
            </a>
        </div>
    </div>
</div>

{{-- ─── What it has earned and cost ───────────────────────────────────────── --}}
@if($sold || $bought)
    <div class="card mb-3">
        <div class="card-header">{{ __('What it has done for the shop') }}</div>
        <div class="card-body">
            <div class="row g-3">
                @if($sold)
                    <div class="col-6 col-lg-3">
                        <div class="text-secondary small">{{ __('Takings') }}</div>
                        <div class="fs-5 money">{{ money($sold['revenue'], false, $lens) }}</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="text-secondary small">{{ __('What those units cost') }}</div>
                        <div class="fs-5 money">{{ money($sold['cost'], false, $lens) }}</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="text-secondary small">{{ __('Profit') }}</div>
                        <div class="fs-5 money fw-semibold {{ $sold['profit'] < 0 ? 'text-danger' : '' }}">
                            {{ money($sold['profit'], false, $lens) }}
                        </div>
                    </div>
                @endif
                @if($bought)
                    <div class="col-6 col-lg-3">
                        <div class="text-secondary small">{{ __('Spent on it') }}</div>
                        <div class="fs-5 money">{{ money($bought['spend'], false, $lens) }}</div>
                    </div>
                @endif
            </div>

            <div class="small text-secondary mt-3">
                {{-- ⚠️ Said out loud, because the two figures are not two
                     halves of one sum: the takings are what has been SOLD, the
                     spend is what has been BOUGHT, and what is still on the
                     shelf sits between them. --}}
                {{ __('Takings are what has been sold; the spend is everything bought, including what is still on the shelf. The profit is the FIFO cost of the units that actually went out.') }}
            </div>
        </div>
    </div>
@endif

{{-- ─── The two people the shop deals with most over it ────────────────────── --}}
@if($bestSupplier || $bestCustomer)
    <div class="row g-3 mb-3">
        @if($bestSupplier)
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">{{ __('You buy it from') }}</div>
                    <div class="card-body">
                        @can('suppliers.view')
                            <a href="{{ route('suppliers.show', $bestSupplier->id) }}" class="fs-5 text-decoration-none">
                                {{ $bestSupplier->name }}
                            </a>
                        @else
                            <div class="fs-5">{{ $bestSupplier->name }}</div>
                        @endcan
                        <div class="small text-secondary mt-1">
                            {{ __(':units bought, :amount spent', [
                                'units' => qty((int) $bestSupplier->units, $product->unit),
                                'amount' => money((int) $bestSupplier->spend, false, $lens),
                            ]) }}
                        </div>
                        {{-- ⚠️ The date is its own element, not a :date inside
                             the sentence. Three of this shop's four languages
                             are right-to-left, and a bare 2026-09-04 dropped
                             into RTL text is reordered by the bidi algorithm
                             into 04-09-2026. `.app-code` is LTR inside and one
                             box from the outside — the rule the rest of the
                             shop already follows for codes and dates. --}}
                        <div class="small text-secondary">
                            {{ __('Last on') }}
                            <span class="app-code">{{ \Illuminate\Support\Carbon::parse($bestSupplier->last_on)->format(setting('date_format', 'Y-m-d')) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($bestCustomer)
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">{{ __('Your best customer for it') }}</div>
                    <div class="card-body">
                        @can('customers.view')
                            <a href="{{ route('customers.show', $bestCustomer->id) }}" class="fs-5 text-decoration-none">
                                {{ $bestCustomer->name }}
                            </a>
                        @else
                            <div class="fs-5">{{ $bestCustomer->name }}</div>
                        @endcan
                        <div class="small text-secondary mt-1">
                            {{ __(':units bought, :amount spent', [
                                'units' => qty((int) $bestCustomer->units, $product->unit),
                                'amount' => money((int) $bestCustomer->takings, false, $lens),
                            ]) }}
                        </div>
                        {{-- ⚠️ The date is its own element, not a :date inside
                             the sentence. Three of this shop's four languages
                             are right-to-left, and a bare 2026-09-04 dropped
                             into RTL text is reordered by the bidi algorithm
                             into 04-09-2026. `.app-code` is LTR inside and one
                             box from the outside — the rule the rest of the
                             shop already follows for codes and dates. --}}
                        <div class="small text-secondary">
                            {{ __('Last on') }}
                            <span class="app-code">{{ \Illuminate\Support\Carbon::parse($bestCustomer->last_on)->format(setting('date_format', 'Y-m-d')) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif

{{-- ─── The invoices that sold it ──────────────────────────────────────────── --}}
@if($soldOn->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header">{{ __('Invoices that sold it') }}</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-cards">
                <thead>
                <tr>
                    <th>{{ __('Document') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th class="money">{{ __('Quantity') }}</th>
                    <th class="money">{{ __('They paid') }}</th>
                    <th class="text-end"></th>
                </tr>
                </thead>
                <tbody>
                @foreach($soldOn as $line)
                    <tr>
                        <td class="list-card-title"><x-document-link :document="$line->sale" :kind="false" /></td>
                        <td data-label="{{ __('Date') }}">
                            <span class="app-code">{{ $line->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</span>
                        </td>
                        <td data-label="{{ __('Customer') }}">{{ $line->sale->customer->displayName() }}</td>
                        <td class="money" data-label="{{ __('Quantity') }}">{{ number_format($line->quantity) }}</td>
                        <td class="money" data-label="{{ __('They paid') }}">{{ money($line->unit_price, false, $lens) }}</td>
                        <td class="list-card-actions text-end">
                            {{-- ⚠️ Soran: "I tab to return faulty item then system
                                 search all invoice are I sale this product and
                                 after select one open it". This is that tab — the
                                 swap page, on this exact line, with the shelf
                                 already read. --}}
                            @can('swaps.create')
                                @if($line->returnableQuantity() > 0)
                                    <a href="{{ route('swaps.create', ['sale_item' => $line->id]) }}"
                                       class="btn btn-sm btn-outline-primary">{{ __('Came back faulty') }}</a>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- ─── And the purchases that brought it in ───────────────────────────────── --}}
@if($boughtOn->isNotEmpty())
    <div class="card">
        <div class="card-header">{{ __('Purchases that brought it in') }}</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-cards">
                <thead>
                <tr>
                    <th>{{ __('Document') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Supplier') }}</th>
                    <th class="money">{{ __('Quantity') }}</th>
                    <th class="money">{{ __('Cost each') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($boughtOn as $line)
                    <tr>
                        <td class="list-card-title"><x-document-link :document="$line->purchase" :kind="false" /></td>
                        <td data-label="{{ __('Date') }}">
                            <span class="app-code">{{ $line->purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }}</span>
                        </td>
                        <td data-label="{{ __('Supplier') }}">{{ $line->purchase->supplier?->name ?? '—' }}</td>
                        <td class="money" data-label="{{ __('Quantity') }}">{{ number_format($line->quantity) }}</td>
                        <td class="money" data-label="{{ __('Cost each') }}">{{ money($line->unit_price, false, $lens) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
