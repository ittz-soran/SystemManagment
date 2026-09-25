@php
    $unit = $product->unit;
    $canComeBack = $state['canComeBack'];
    $supplier = $state['supplier'];
    $chosen = $wanted ?? null;
@endphp

<div class="card">
    <div class="card-header">
        {{ __('A different product') }}
        <span class="badge text-bg-light app-code ms-1">SRT + INV</span>
    </div>
    <div class="card-body">
        <ul class="small text-secondary">
            <li>{{ __(':document drops by what comes back, and a new invoice records what went out.', ['document' => $line->sale->document_no]) }}</li>
            <li>{{ __('Both are written together — both, or neither.') }}</li>
            <li>{{ __('The lines have to change: the profit report reads what each product earned off the sale lines, so leaving this one as it is would put the money on the wrong product.') }}</li>
        </ul>

        {{-- Pick the new product first. A reload rather than a script: this
             page already chooses everything else by following a link, and a
             chosen product has to be read back from the server anyway for its
             price and what is on the shelf. --}}
        @unless($chosen)
            <form method="GET" action="{{ route('goods-back.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="sale_item" value="{{ $line->id }}">
                <input type="hidden" name="answer" value="other">
                <div class="col-12 col-md-9">
                    <label for="gb-wanted" class="form-label">{{ __('What is he taking instead?') }}</label>
                    <input id="gb-wanted" type="search" name="w" value="{{ $wantedTerm }}"
                           class="form-control" data-english-digits autocomplete="off"
                           placeholder="{{ __('Scan a barcode, or type a name or SKU') }}">
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-outline-primary">
                        <i class="bi bi-search me-1"></i>{{ __('Find it') }}
                    </button>
                </div>
            </form>

            @if($wantedTerm !== '')
                @if($wantedProducts->isEmpty())
                    <p class="text-secondary small mt-3 mb-0">
                        {{ __('Nothing matches :term.', ['term' => $wantedTerm]) }}
                    </p>
                @else
                    <div class="list-group mt-3">
                        @foreach($wantedProducts as $option)
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"
                               href="{{ route('goods-back.index', ['sale_item' => $line->id, 'answer' => 'other', 'wanted' => $option->id]) }}">
                                <span class="min-w-0">
                                    <span class="d-block text-truncate">{{ $option->name }}</span>
                                    <span class="d-block small text-secondary">
                                        <span dir="ltr">{{ $option->sku }}</span>
                                        · {{ number_format($option->quantity) }} {{ $option->unit }} {{ __('in stock') }}
                                    </span>
                                </span>
                                <span class="money text-nowrap">{{ money($option->sale_price, false, $lens) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        @else
            <form action="{{ route('goods-back.exchange') }}" method="POST" data-guard-submit id="gb-exchange">
                @csrf
                <input type="hidden" name="sale_item_id" value="{{ $line->id }}">
                <input type="hidden" name="product_id" value="{{ $chosen->id }}">

                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap
                            border rounded p-2 mb-3 bg-body-tertiary">
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate">{{ $chosen->name }}</div>
                        <div class="small text-secondary">
                            <span dir="ltr">{{ $chosen->sku }}</span>
                            · {{ number_format($chosen->quantity) }} {{ $chosen->unit }} {{ __('in stock') }}
                        </div>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="{{ route('goods-back.index', ['sale_item' => $line->id, 'answer' => 'other']) }}">
                        {{ __('Choose another') }}
                    </a>
                </div>

                <div class="row g-3 align-items-end">
                    <div class="col-6 col-md-3">
                        <label for="ex-back" class="form-label">{{ __('Coming back') }}</label>
                        <div class="input-group">
                            <input id="ex-back" type="number" name="quantity" dir="ltr"
                                   class="form-control text-end" min="1" max="{{ $canComeBack }}" step="1"
                                   value="{{ old('quantity', 1) }}" required
                                   data-role="ex-back" data-price="{{ $line->unit_price }}">
                            @if($unit !== '')<span class="input-group-text">{{ $unit }}</span>@endif
                        </div>
                        <div class="form-text">{{ __('At most :count.', ['count' => number_format($canComeBack)]) }}</div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="ex-out" class="form-label">{{ __('Going out') }}</label>
                        <div class="input-group">
                            <input id="ex-out" type="number" name="wanted_quantity" dir="ltr"
                                   class="form-control text-end" min="1" step="1"
                                   value="{{ old('wanted_quantity', 1) }}" required data-role="ex-out">
                            @if($chosen->unit !== '')<span class="input-group-text">{{ $chosen->unit }}</span>@endif
                        </div>
                        <div class="form-text">{{ __(':count on the shelf.', ['count' => number_format($chosen->quantity)]) }}</div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="ex-price" class="form-label">{{ __('At each') }}</label>
                        <input id="ex-price" type="number" name="unit_price" dir="ltr" min="0" step="1"
                               class="form-control text-end" required data-role="ex-price"
                               value="{{ old('unit_price', $chosen->sale_price) }}">
                        <div class="form-text">{{ __('The shelf price, unless you change it.') }}</div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="ex-method" class="form-label">{{ __('Settle by') }}</label>
                        <select id="ex-method" name="payment_method" class="form-select">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="bank">{{ __('Bank') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>
                </div>

                {{-- The one figure this whole screen exists to show. --}}
                {{-- The three sentences the script picks between, translated
                     here where translation happens rather than assembled from
                     fragments in JavaScript. --}}
                <div class="alert alert-secondary mt-3 mb-0" data-role="ex-settlement"
                     data-same="{{ __('Nothing changes hands — the same money both ways.') }}"
                     data-owes="{{ __(':name pays :amount', ['name' => $line->sale->customer->displayName(), 'amount' => ':amount']) }}"
                     data-refunds="{{ __('The shop refunds :amount', ['amount' => ':amount']) }}">
                    <div class="small">
                        {{ __('Back') }}:
                        <span class="money" data-role="ex-credit">{{ money($line->unit_price, false, $lens) }}</span>
                        · {{ __('Out') }}:
                        <span class="money" data-role="ex-charge">{{ money($chosen->sale_price, false, $lens) }}</span>
                    </div>
                    <div class="fs-5 fw-semibold" data-role="ex-net">—</div>
                </div>

                @if(! $line->sale->customer->is_system)
                    <div class="row g-3 align-items-end mt-0">
                        <div class="col-12 col-md-5">
                            <label for="ex-paid" class="form-label">{{ __('Paying now') }}</label>
                            <input id="ex-paid" type="number" name="amount_paid" dir="ltr" min="0" step="1"
                                   class="form-control text-end" value="{{ old('amount_paid', 0) }}"
                                   data-role="ex-paid">
                            <div class="form-text">
                                {{ __('Leave it at zero to put the difference on his account.') }}
                            </div>
                        </div>
                    </div>
                @else
                    <p class="form-text mt-2 mb-0">
                        {{ __('A walk-in settles at the counter, so the till takes the new price in and gives the old one back. What he hands over is the difference above.') }}
                    </p>
                @endif

                @if($supplier)
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" value="1" id="ex-faulty" name="faulty">
                        <label class="form-check-label" for="ex-faulty">
                            {{ __('It came back faulty — send it to :supplier too', ['supplier' => $supplier]) }}
                        </label>
                    </div>
                @endif

                <div class="d-grid mt-3">
                    <button type="submit" class="btn btn-primary btn-lg" data-submitting-text="{{ __('Saving…') }}">
                        {{ __('Exchange it') }}
                    </button>
                </div>
            </form>
        @endunless
    </div>
</div>
