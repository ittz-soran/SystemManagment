@php
    $unit = $product->unit;
    $answer = in_array(request('answer'), ['same', 'other', 'money'], true) ? request('answer') : 'money';
    $canComeBack = $state['canComeBack'];
    $mostToSwap = $state['mostToSwap'];
    $swapState = $state['swapState'];
    $swapCost = $state['replacementCost'] - $state['lineCost'];
    $supplier = $state['supplier'];
    $tabs = [
        'same' => [__('The same thing again'), 'SWP · '.__('invoice not touched'), $may['swap']],
        'other' => [__('A different product'), 'SRT + INV · '.__('invoice changes'), $may['exchange']],
        'money' => [__('His money back'), 'SRT · '.__('invoice changes'), $may['refund']],
    ];
@endphp

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="fs-5 fw-semibold">{{ $product->name }}</div>
                <div class="small text-secondary" dir="ltr">{{ $product->sku }}</div>
            </div>
            <a href="{{ route('goods-back.index', ['product' => $product->id]) }}"
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
                <div class="text-secondary">{{ __('They paid') }}</div>
                <div class="money">{{ money($line->unit_price, false, $lens) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-secondary">{{ __('Can still come back') }}</div>
                <div>{{ number_format($canComeBack) }} {{ $unit }}</div>
            </div>
        </div>
    </div>
</div>

{{-- The question a shopkeeper has already asked the customer, put to the page
     in the same words. Links rather than tab JavaScript: each answer is its
     own address, so a reload keeps it and the back arrow works. --}}
<p class="text-secondary small mb-2">
    {{ __('What does :name want?', ['name' => $line->sale->customer->displayName()]) }}
</p>

<div class="row g-2 mb-3">
    @foreach($tabs as $key => $tab)
        @if($tab[2])
            <div class="col-12 col-md-4 d-grid">
                <a href="{{ route('goods-back.index', ['sale_item' => $line->id, 'answer' => $key]) }}"
                   class="btn text-start {{ $answer === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                   @if($answer === $key) aria-current="true" @endif>
                    <span class="d-block fw-semibold">{{ $tab[0] }}</span>
                    <span class="d-block small app-code {{ $answer === $key ? '' : 'text-secondary' }}">{{ $tab[1] }}</span>
                </a>
            </div>
        @endif
    @endforeach
</div>

{{-- ─── The same thing again ──────────────────────────────────────────────── --}}
@if($answer === 'same' && $may['swap'])
    <div class="card">
        <div class="card-header">{{ __('The same thing again') }} <span class="badge text-bg-light app-code ms-1">SWP</span></div>
        <div class="card-body">
            <ul class="small text-secondary">
                <li>{{ __(':name walks out with another :product', ['name' => $line->sale->customer->displayName(), 'product' => $product->name]) }}</li>
                <li>{{ __(':document is not touched — same quantity, same price, same printed invoice.', ['document' => $line->sale->document_no]) }}</li>
                @if($supplier)
                    <li>{{ __('The faulty one goes back to :supplier at what they were paid.', ['supplier' => $supplier]) }}</li>
                @endif
            </ul>

            @if(! $swapState['allowed'])
                <div class="d-flex align-items-start gap-2 text-secondary">
                    <i class="bi bi-x-circle mt-1"></i>
                    <div>{{ $swapState['reason'] }}</div>
                </div>
            @else
                @if($swapCost > 0)
                    <div class="alert alert-warning py-2 small">
                        {{ __('Costs the shop :amount for each one — the replacement comes off a dearer layer, and the supplier only refunds what they were paid.', ['amount' => money($swapCost, false, $lens)]) }}
                    </div>
                @endif

                <form action="{{ route('goods-back.swap') }}" method="POST" data-guard-submit>
                    @csrf
                    <input type="hidden" name="sale_item_id" value="{{ $line->id }}">

                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-4">
                            <label for="swap-qty" class="form-label">{{ __('How many') }}</label>
                            <div class="input-group">
                                <input id="swap-qty" type="number" name="quantity" dir="ltr"
                                       class="form-control text-end" min="1" max="{{ $mostToSwap }}" step="1"
                                       value="{{ old('quantity', 1) }}" required>
                                @if($unit !== '')<span class="input-group-text">{{ $unit }}</span>@endif
                            </div>
                            <div class="form-text">
                                {{ __('At most :count — what is left on the line, and what is on the shelf.', ['count' => number_format($mostToSwap)]) }}
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label for="swap-note" class="form-label">{{ __('Note') }}</label>
                            <input id="swap-note" name="note" class="form-control" maxlength="500"
                                   value="{{ old('note') }}"
                                   placeholder="{{ __('Not charging, screen dead, dead on arrival…') }}">
                        </div>
                    </div>

                    <div class="d-grid mt-3">
                        <button type="submit" class="btn btn-primary btn-lg" data-submitting-text="{{ __('Saving…') }}">
                            {{ __('Swap it') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endif

{{-- ⚠️ **One product at a time here, by construction.** Searching by item
     finds one line, but a customer bringing back three different things from
     one receipt should still make ONE document — so the invoice's own return
     screen stays reachable for exactly that, and the menu simply no longer
     points at it. Shown only where it helps: a one-line invoice has no other
     lines to offer. --}}
@if($may['refund'] && $line->sale->items->count() > 1)
    <p class="small mb-3">
        <a href="{{ route('sale-returns.create', ['sale' => $line->sale, 'line' => $line->id]) }}">
            <i class="bi bi-list-ul me-1"></i>{{ __('Taking back more than one thing from :document? Do the whole invoice at once.', ['document' => $line->sale->document_no]) }}
        </a>
    </p>
@endif

{{-- ─── A different product ───────────────────────────────────────────────── --}}
@if($answer === 'other' && $may['exchange'])
    @include('goods-back.partials.exchange')
@endif

{{-- ─── His money back ────────────────────────────────────────────────────── --}}
@if($answer === 'money' && $may['refund'])
    <div class="card">
        <div class="card-header">{{ __('His money back') }} <span class="badge text-bg-light app-code ms-1">SRT</span></div>
        <div class="card-body">
            <ul class="small text-secondary">
                <li>{{ __('The units go back on the shelf, into the batches they came out of.') }}</li>
                <li>{{ __('What they paid comes off what they owe — anything left over is handed back.') }}</li>
                <li>{{ __(':document’s line drops by what comes back.', ['document' => $line->sale->document_no]) }}</li>
            </ul>

            <form action="{{ route('goods-back.refund') }}" method="POST" data-guard-submit>
                @csrf
                <input type="hidden" name="sale_item_id" value="{{ $line->id }}">

                <div class="row g-3 align-items-end">
                    <div class="col-6 col-md-3">
                        <label for="refund-qty" class="form-label">{{ __('How many') }}</label>
                        <div class="input-group">
                            <input id="refund-qty" type="number" name="quantity" dir="ltr"
                                   class="form-control text-end" min="1" max="{{ $canComeBack }}" step="1"
                                   value="{{ old('quantity', 1) }}" required
                                   data-role="refund-qty" data-price="{{ $line->unit_price }}">
                            @if($unit !== '')<span class="input-group-text">{{ $unit }}</span>@endif
                        </div>
                        <div class="form-text">
                            {{ __('At most :count.', ['count' => number_format($canComeBack)]) }}
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="refund-method" class="form-label">{{ __('Refund by') }}</label>
                        <select id="refund-method" name="payment_method" class="form-select">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="bank">{{ __('Bank') }}</option>
                            <option value="transfer">{{ __('Transfer') }}</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="refund-reason" class="form-label">{{ __('Reason') }}</label>
                        <input id="refund-reason" name="reason" class="form-control" maxlength="500"
                               value="{{ old('reason') }}"
                               placeholder="{{ __('Did not need it, wrong one, changed his mind…') }}">
                    </div>
                </div>

                @if($supplier)
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" value="1" id="refund-faulty" name="faulty">
                        <label class="form-check-label" for="refund-faulty">
                            {{ __('It came back faulty — send it to :supplier too', ['supplier' => $supplier]) }}
                            <span class="d-block small text-secondary">
                                {{ __('Writes a PRT in the same breath, at the price that supplier was paid. Leave it off when the customer simply did not need it.') }}
                            </span>
                        </label>
                    </div>
                @endif

                <div class="alert alert-secondary py-2 small mt-3 mb-0">
                    {{ __('Refund') }}
                    <span class="money fw-semibold" data-role="refund-total">{{ money($line->unit_price, false, $lens) }}</span>
                </div>

                <div class="d-grid mt-3">
                    <button type="submit" class="btn btn-primary btn-lg" data-submitting-text="{{ __('Saving…') }}">
                        {{ __('Take it back') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
