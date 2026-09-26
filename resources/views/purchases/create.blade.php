@extends('layouts.app')

@php
    // The edit screen is this same cart with the purchase's lines preloaded and
    // the payment fields hidden — Section 8 keeps payments untouched by an edit.
    $editing = isset($purchase);
@endphp

@section('title', $editing ? __('Edit purchase') : __('New purchase'))
@if($editing)
    @section('subheading', $purchase->document_no)
@endif

{{-- Editing came from the purchase; a new purchase came from the list. --}}
@section('back')
    @if($editing)
        <x-back-link :to="route('purchases.show', $purchase)" :label="$purchase->document_no"
                     permission="purchases.view" />
    @else
        <x-back-link :to="route('purchases.index')" :label="__('Purchase history')"
                     remember="purchases" permission="purchases.view" />
    @endif
@endsection

@section('content')
    @unless($editing)
        @include('partials.held-carts', [
            'heldCarts' => $heldCarts,
            'resumeRoute' => 'purchases.create',
        ])
    @endunless

    <form action="{{ $editing ? route('purchases.update', $purchase) : route('purchases.store') }}"
          method="POST" id="purchase-form" data-guard-submit>
        @csrf
        @if($editing) @method('PUT') @endif

        {{-- Carried through so the hold is spent when the purchase is saved,
             and not one moment before. --}}
        @if(! $editing && ($held ?? null))
            <input type="hidden" name="held_cart_id" value="{{ $held->id }}">
        @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    @include('partials.cart-search', ['suffix' => ''])
                </div>

                <div class="card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 table-cart">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money" style="width: 7rem">{{ __('Quantity') }}</th>
                                {{-- Section 2b: no per-line currency. A supplier
                                     invoices in ONE currency, and the box below
                                     takes whichever one the invoice is in. --}}
                                <th class="money" style="width: 11rem">{{ __('Unit price') }}</th>
                                <th class="money" style="width: 9rem">{{ __('Total') }}</th>
                                <th style="width: 3rem"></th>
                            </tr>
                            </thead>
                            <tbody id="cart-body"></tbody>
                        </table>
                    </div>

                    <div id="cart-empty" class="text-center text-secondary py-5">
                        <i class="bi bi-bag fs-1 d-block mb-2 opacity-50"></i>
                        {{ __('No lines yet. Scan a product to begin.') }}
                    </div>
                </div>

                {{-- The same box again, under the last line added. With
                     twenty-five things in the cart the one at the top has
                     scrolled away, and the twenty-sixth scan should not mean
                     scrolling back up to find somewhere to put it. --}}
                <div class="card mt-3">
                    @include('partials.cart-search', ['suffix' => '-bottom'])
                </div>

                {{-- Section 4: "The same product may appear on two lines at two
                     different prices — supported, never merged." --}}
                <p class="form-text mt-2">
                    {{ __('The same product can appear on two lines at two prices. Each line becomes its own cost layer.') }}
                </p>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <label for="supplier_id" class="form-label">{{ __('Supplier') }}</label>
                                @can('suppliers.create')
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none"
                                            data-bs-toggle="modal" data-bs-target="#new-supplier-modal">
                                        <i class="bi bi-plus-lg"></i>{{ __('New') }}
                                    </button>
                                @endcan
                            </div>
                            <select id="supplier_id" name="supplier_id" class="form-select" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}"
                                            @selected(old('supplier_id', $editing ? $purchase->supplier_id : null) == $supplier->id)>
                                        {{ $supplier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="supplier_invoice_no" class="form-label">{{ __("Supplier's invoice number") }}</label>
                            <input id="supplier_invoice_no" name="supplier_invoice_no" class="form-control" dir="ltr"
                                   value="{{ old('supplier_invoice_no', $editing ? $purchase->supplier_invoice_no : null) }}">
                            <div class="form-text">{{ __('Their number on their paperwork. Useful when reconciling.') }}</div>
                        </div>

                        {{-- Where the delivery went — Soran, 2026-09-18:
                             "add purchase directly to other rooms, but sale
                             always in main".

                             ⚠️ Shown only when the shop HAS another room. A
                             shop with one room would otherwise get a control
                             with one answer, which is a question it never
                             needed to be asked. --}}
                        @if($rooms->count() > 1)
                            <div class="mb-3">
                                <label for="room_id" class="form-label">{{ __('Goods arrive in') }}</label>
                                @php
                                    $defaultRoom = $rooms->firstWhere('is_main', true) ?? $rooms->first();
                                    $chosenRoom = (int) old('room_id', $editing ? $purchase->room_id : $defaultRoom?->id);
                                @endphp
                                <select id="room_id" name="room_id" class="form-select">
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}" @selected($chosenRoom === $room->id)>
                                            {{ $room->name }}
                                        </option>
                                    @endforeach
                                </select>
                                {{-- The reason the old rule existed, said out
                                     loud rather than enforced. --}}
                                <div class="form-text">{{ __('Stock in another room cannot be sold at the till until it is moved.') }}</div>
                                @error('room_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="purchase_date" class="form-label">{{ __('Date') }}</label>
                            <input id="purchase_date" type="date" name="purchase_date" class="form-control"
                                   value="{{ old('purchase_date', $editing ? $purchase->purchase_date->toDateString() : today()->toDateString()) }}" required>
                        </div>

                        {{-- Section 2b: the foreign-currency entry helper.
                             Section 6b hard-coded this to dollars; the choice is
                             now whichever currencies the shop keeps. What has
                             not changed is the rule underneath — only base-
                             currency integers are ever stored, and this is a
                             calculator on the entry form.

                             ⚠️ ONE foreign currency per invoice. A supplier
                             invoices in one currency, and the rate printed
                             beside the figures has to be a single rate. Each
                             line still chooses between the base currency and
                             this one. --}}
                        @if($foreignCurrencies->isNotEmpty())
                            @php $chosenCode = old('document_currency', $documentCurrency); @endphp

                            <div>
                                <label for="document_currency" class="form-label">{{ __('Invoice currency') }}</label>

                                <select id="document_currency" name="document_currency" class="form-select mb-2">
                                    <option value="{{ $base->code }}" @selected($chosenCode === $base->code)>
                                        {{ $base->name }} ({{ $base->code }})
                                    </option>
                                    @foreach($foreignCurrencies as $currency)
                                        <option value="{{ $currency->code }}" @selected($chosenCode === $currency->code)>
                                            {{ $currency->name }} ({{ $currency->code }})
                                        </option>
                                    @endforeach
                                </select>

                                <div class="input-group" id="rate-box">
                                    <span class="input-group-text app-code" id="rate-of">1</span>
                                    <input id="exchange_rate" type="number" step="1" min="1" name="exchange_rate"
                                           class="form-control text-end" dir="ltr"
                                           value="{{ old('exchange_rate', $documentRate ?: '') }}">
                                    <span class="input-group-text app-code">{{ $base->mark() }}</span>
                                </div>
                                <div class="form-text" id="rate-warning"></div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">{{ __('Subtotal') }}</span>
                            <span class="money" id="subtotal">0</span>
                        </div>

                        {{-- ⚠️ Section 2b: the box takes the invoice currency,
                             the hidden field beside it posts base-currency
                             units. Only base integers are ever stored, on this
                             screen as on every other. --}}
                        <div class="mb-3">
                            <label for="discount_shown" class="form-label small">{{ __('Invoice discount') }}</label>
                            <div class="input-group input-group-sm">
                                <input id="discount_shown" type="number" step="1"
                                       class="form-control text-end" dir="ltr" value="0">
                                <span class="input-group-text app-code" data-role="mark">{{ $base->mark() }}</span>
                            </div>
                            <input type="hidden" name="discount_amount" id="discount_amount"
                                   value="{{ old('discount_amount', $editing ? $purchase->discount_amount : 0) }}">
                            {{-- Section 6: signed, because a supplier may round UP.
                                 It never touches item prices or batch costs. --}}
                            <div class="form-text">
                                {{ __('Negative if the supplier rounded up. This never changes any batch cost.') }}
                            </div>
                        </div>

                        <div class="text-secondary small">{{ __('Grand total') }}</div>
                        <div class="running-total" id="grand-total" data-role="running-total">0</div>
                        {{-- What the books will actually hold. The figure above
                             is the same money said in the invoice's currency. --}}
                        <div class="text-secondary small mb-3 d-none" dir="ltr" id="grand-total-base"></div>

                        @if($editing)
                            {{-- Section 2b: base currency, deliberately. This is money
                                 that has already moved, and the books recorded it in
                                 dinars at whatever rate applied on the day. --}}
                            <div class="alert alert-secondary py-2 small mb-0">
                                {{ __('Payments are not changed by an edit. The new total must still cover the :paid already paid.', ['paid' => money($purchase->amountPaid())]) }}
                            </div>
                        @else
                            <div class="mb-3">
                                <label for="amount_paid_shown" class="form-label">{{ __('Paid now') }}</label>
                                <div class="input-group">
                                    <input id="amount_paid_shown" type="number" step="1" min="0"
                                           class="form-control text-end" dir="ltr" value="0">
                                    <span class="input-group-text app-code" data-role="mark">{{ $base->mark() }}</span>
                                    <button type="button" class="btn btn-outline-secondary" id="pay-full">{{ __('Full') }}</button>
                                </div>
                                <input type="hidden" name="amount_paid" id="amount_paid" value="0">
                                <div class="form-text" id="due-note"></div>
                            </div>

                            <div>
                                <label for="payment_method" class="form-label">{{ __('Method') }}</label>
                                <select id="payment_method" name="payment_method" class="form-select">
                                    <option value="cash">{{ __('Cash') }}</option>
                                    <option value="bank">{{ __('Bank') }}</option>
                                    <option value="transfer">{{ __('Transfer') }}</option>
                                </select>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ⚠️ Not sticky, and it used to be. A bottom-sticky block is
                     pinned to the bottom of the window and paints over whatever
                     the last field is — on the sale screen that made the Method
                     dropdown unclickable on an empty cart at 1280×800. The long
                     version of the reasoning is in sales/create.blade.php; this
                     panel has the same shape and lost it for the same reason.
                     F2 already saves from anywhere here. --}}
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" id="save-purchase" disabled
                            data-role="save" data-submitting-text="{{ __('Saving…') }}">
                        {{ $editing ? __('Save changes') : __('Save purchase') }} <kbd class="ms-1">F2</kbd>
                    </button>
                    @unless($editing)
                        {{-- Twenty-five things scanned and the supplier still
                             not chosen. Put it down; nothing is written. --}}
                        <button type="button" class="btn btn-outline-secondary" id="hold-cart" disabled>
                            <i class="bi bi-pause-circle me-1"></i>{{ __('Hold this cart') }}
                        </button>
                    @endunless

                    <a href="{{ $editing ? route('purchases.show', $purchase) : route('purchases.index') }}"
                       class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
        {{-- The till bar — a phone only. Same reasoning as the sale screen, and
             the same reason it lives inside the form: app.js gives the
             hold-to-save guard to the form's own buttons, and a button attached
             from outside with `form="…"` is never walked. --}}
        <div class="app-till-bar d-md-none no-print">
            <div class="min-w-0">
                <div class="app-till-bar-label">{{ __('Grand total') }}</div>
                <div class="app-till-bar-total money" data-role="running-total">0</div>
            </div>

            <button type="submit" class="btn btn-primary" disabled
                    data-role="save" data-submitting-text="{{ __('Saving…') }}">
                {{ $editing ? __('Save changes') : __('Save purchase') }}
            </button>
        </div>
    </form>
    <x-leave-guard />
@endsection

@push('scripts')
    <script>
        (() => {
            /**
             * The same search box, twice.
             *
             * Top and bottom are one behaviour rather than two: the same lookup,
             * the same keys, the same results. Whichever one is being typed in
             * is the one that shows its results; the other stays quiet.
             */
            const searches = ['', '-bottom']
                .map((suffix) => ({
                    input: document.getElementById('product-search' + suffix),
                    results: document.getElementById('search-results' + suffix),
                }))
                .filter((pair) => pair.input && pair.results);

            // Where scanning happens once there is anything in the cart: under
            // the last line added, not off the top of the screen.
            const scanner = searches[searches.length - 1];

            // The pair currently being typed in.
            let searchInput = searches[0].input;
            let resultsBox = searches[0].results;
            const cartBody = document.getElementById('cart-body');
            const cartEmpty = document.getElementById('cart-empty');
            const subtotalEl = document.getElementById('subtotal');
            // Said twice on a phone — the panel and the till bar — and once on
            // a laptop. Both read the same number from one place.
            const grandTotalEls = document.querySelectorAll('[data-role="running-total"]');
            const discountInput = document.getElementById('discount_shown');
            const paidInput = document.getElementById('amount_paid_shown');
            const dueNote = document.getElementById('due-note');
            const rateInput = document.getElementById('exchange_rate');
            const rateWarning = document.getElementById('rate-warning');
            const rateOf = document.getElementById('rate-of');
            const rateBox = document.getElementById('rate-box');
            const currencySelect = document.getElementById('document_currency');
            const grandTotalBase = document.getElementById('grand-total-base');
            const saveButtons = document.querySelectorAll('[data-role="save"]');

            /*
             * Section 2b: the whole document is written in ONE currency.
             *
             * The invoice currency governs every money box on this screen —
             * prices, the discount, what was paid — and every figure it draws.
             * What is SAVED is unchanged: base-currency integers, the same ones
             * that would have been saved had the whole thing been typed in
             * dinars. Each visible box has a hidden field beside it holding
             * exactly that, and the hidden field is what the form posts.
             */
            const currencies = @json($currencyMeta);
            const baseCode = @json($base->code);
            const baseMark = @json($base->mark());

            /** The currency this invoice is written in. */
            const invoiceCode = () => currencySelect ? currencySelect.value : baseCode;

            /** True when the screen is already showing the books' own currency. */
            const inBase = () => invoiceCode() === baseCode;

            /** Base units per one unit of the invoice currency, as typed. */
            const rate = () => Number(rateInput?.value || 0);

            const markOf = (code) => currencies[code]?.mark ?? code;
            const placesOf = (code) => Number(currencies[code]?.decimals || 0);
            const minorPer = (code) => Number(currencies[code]?.minorPerMajor || 1);

            /** The rate the shop has saved for a currency — what the box starts at. */
            const savedRate = (code) => Number(currencies[code]?.rate || 0);

            /** A box's step: whole units, or that currency's places. */
            const stepFor = (code) => {
                const places = placesOf(code);

                return places === 0 ? '1' : '0.' + '0'.repeat(places - 1) + '1';
            };

            /**
             * A typed figure, as the base-currency integer to store.
             *
             * Section 6b: round the UNIT price to a whole base unit here, then
             * multiply by quantity. Never convert a line total and divide.
             */
            const toBase = (typed) => inBase()
                ? Math.round(Number(typed || 0))
                : Math.round(Number(typed || 0) * rate());

            /** A stored base integer, as the number to put in a box. */
            function fromBase(stored) {
                if (inBase()) return Number(stored || 0);

                const r = rate();

                return r > 0 ? Number((Number(stored || 0) / r).toFixed(placesOf(invoiceCode()))) : 0;
            }

            /** A stored base integer, written the way this screen reads. */
            function show(stored) {
                if (inBase()) return format(stored);

                const places = placesOf(invoiceCode());

                return fromBase(stored).toLocaleString('en-US', {
                    minimumFractionDigits: places,
                    maximumFractionDigits: places,
                });
            }

            /** The same figure said in the books' own currency. */
            const showBase = (stored) => format(stored) + ' ' + baseMark;

            // Section 8: an edit starts from the purchase's current lines.
            const cart = @json($cartLines ?? []);
            let highlighted = -1;
            let searchTimer = null;

            // One implementation, in app.js — the running total and the
            // saved invoice must be written the same way. See window.appMoney.
            const format = (n) => window.appMoney(n);

            function render() {
                cartBody.innerHTML = '';

                cart.forEach((line, index) => {
                    const row = document.createElement('tr');

                    row.innerHTML = `
                        <td class="cart-cell-product">
                            <div class="fw-medium">${escapeHtml(line.name)}</div>
                            <div class="small text-secondary app-code">${escapeHtml(line.sku)}</div>
                            <input type="hidden" name="lines[${index}][product_id]" value="${line.id}">
                            {{-- Section 6b: what the line was written in, and
                                 what was typed — kept so the document can show
                                 it back and an edit reopens the box as it was.
                                 ⚠️ Scaled by THAT currency's minor units, not by
                                 a hard-coded hundred: a yen has no decimals. --}}
                            <input type="hidden" name="lines[${index}][entered_currency]" value="${escapeHtml(invoiceCode())}">
                            <input type="hidden" name="lines[${index}][entered_amount]"
                                   value="${inBase() || line.typed === null ? '' : Math.round(line.typed * minorPer(invoiceCode()))}">
                        </td>
                        <td class="cart-cell-qty">
                            {{-- Section 4: a product is counted in its own unit,
                                 and the same unit buys and sells it. Writing it
                                 beside the box is the whole of it — there is no
                                 conversion to a second unit, by design. --}}
                            <div class="input-group input-group-sm flex-nowrap">
                                <input type="number" min="1" step="1" dir="ltr"
                                       class="form-control text-end" style="min-width: 3.25rem"
                                       name="lines[${index}][quantity]" value="${line.quantity}"
                                       data-role="qty" data-index="${index}"
                                       data-numpad="@json(__('Quantity'))" data-numpad-min="1">
                                ${line.unit ? `<span class="input-group-text px-1 small text-truncate" data-role="unit"
                                                     style="max-width: 3.5rem" title="${escapeHtml(line.unit)}">${escapeHtml(line.unit)}</span>` : ''}
                            </div>
                        </td>
                        <td class="cart-cell-price">
                            <div class="input-group input-group-sm">
                                <input type="number" min="0" step="${stepFor(invoiceCode())}" dir="ltr"
                                       class="form-control text-end"
                                       value="${line.typed ?? fromBase(line.price)}"
                                       data-role="price" data-index="${index}"
                                       data-numpad="${escapeHtml(line.name)}"
                                       data-numpad-decimals="${placesOf(invoiceCode())}">
                                <span class="input-group-text app-code">${escapeHtml(markOf(invoiceCode()))}</span>
                            </div>
                            {{-- What this line will actually cost the books —
                                 the batch cost FIFO is about to open. --}}
                            ${inBase() ? '' : `<div class="small text-secondary text-end" dir="ltr" data-role="converted">= ${escapeHtml(showBase(line.price))}</div>`}
                            <input type="hidden" name="lines[${index}][unit_price]" value="${line.price}">
                        </td>
                        <td class="money fw-semibold cart-cell-total">${show(line.quantity * line.price)}</td>
                        <td class="cart-cell-actions">
                            <div class="btn-group btn-group-sm">
                                {{-- One delivery can bring the same thing in at
                                     two prices — the last few of an old carton
                                     and the first of a new one — and FIFO wants
                                     them as two batches, not an average. --}}
                                <button type="button" class="btn btn-outline-secondary"
                                        data-role="split" data-index="${index}"
                                        title="@json(__('Another line for this product, at its own price'))"
                                        aria-label="@json(__('Another line for this product, at its own price'))">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger"
                                        data-role="remove" data-index="${index}" aria-label="@json(__('Remove'))">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </td>`;

                    cartBody.appendChild(row);
                });

                cartEmpty.classList.toggle('d-none', cart.length > 0);
                saveButtons.forEach((b) => { b.disabled = cart.length === 0; });

                // Nothing to put down until something is in it.
                const hold = document.getElementById('hold-cart');
                if (hold) hold.disabled = cart.length === 0;
                recalculate();
            }

            /**
             * Update one row's figures in place.
             *
             * render() replaces cartBody.innerHTML, which destroys the focused
             * input — calling it from an 'input' handler meant a price could
             * never be more than one digit long.
             */
            function refreshRow(index) {
                const line = cart[index];
                const row = cartBody.querySelectorAll('tr')[index];
                if (! line || ! row) return;

                row.querySelector('.money').textContent = show(line.quantity * line.price);

                // ⚠️ The form posts these, not the visible box — which may be
                // dollars, and the books are never in dollars.
                row.querySelector('input[name$="[unit_price]"]').value = line.price;
                row.querySelector('input[name$="[entered_amount]"]').value =
                    inBase() || line.typed === null ? '' : Math.round(line.typed * minorPer(invoiceCode()));

                // The base figure under the box keeps up as the digits arrive.
                const converted = row.querySelector('[data-role="converted"]');

                if (converted) converted.textContent = '= ' + showBase(line.price);

                recalculate();
            }

            function recalculate() {
                // Every figure here is a base-currency integer; only the writing
                // of it changes with the invoice currency.
                const subtotal = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                const grandTotal = subtotal - discount.get();

                subtotalEl.textContent = show(subtotal);
                grandTotalEls.forEach((el) => { el.textContent = show(grandTotal); });

                // What the books will hold, said plainly, whenever the figure
                // above them is not already in the books' own currency.
                grandTotalBase.textContent = showBase(grandTotal);
                grandTotalBase.classList.toggle('d-none', inBase());

                // An edit has no payment fields — Section 8 leaves payments alone.
                if (! paidInput) return;

                const due = grandTotal - paid.get();
                dueNote.textContent = due > 0
                    ? @json(__('Remaining on account:')) + ' ' + show(due) + ' ' + markOf(invoiceCode())
                    : @json(__('Paid in full'));
            }

            /**
             * A money box on this screen: it shows the invoice currency, and
             * posts the base-currency integer through the hidden field.
             *
             * ⚠️ `typed` is the untouched-field rule of Section 2b. A box
             * nobody has typed into is redrawn from its stored figure, so
             * switching currency or correcting the rate can never rewrite a
             * figure by a rounding — 10,000 dinars shown at 1,320 is $7.58,
             * which converts back to 10,006.
             */
            function moneyBox(input, hidden) {
                let stored = Math.round(Number(hidden.value || 0));
                let typed = null;

                function redraw() {
                    input.value = typed ?? fromBase(stored);
                    input.step = stepFor(invoiceCode());
                }

                input.addEventListener('input', () => {
                    typed = Number(input.value || 0);
                    stored = toBase(typed);
                    hidden.value = stored;
                    recalculate();
                });

                return {
                    get: () => stored,
                    /** Put a base figure in, as the screen itself worked it out. */
                    set(value) {
                        stored = Math.round(value);
                        typed = null;
                        hidden.value = stored;
                        redraw();
                    },
                    /** The rate moved: a figure somebody typed is re-converted. */
                    reprice() {
                        if (typed !== null) {
                            stored = toBase(typed);
                            hidden.value = stored;
                        }

                        redraw();
                    },
                    /** The currency changed: the number in the box no longer means anything. */
                    recurrency() {
                        typed = null;
                        redraw();
                    },
                };
            }

            const discount = moneyBox(discountInput, document.getElementById('discount_amount'));
            const paid = paidInput
                ? moneyBox(paidInput, document.getElementById('amount_paid'))
                : { get: () => 0, set() {}, reprice() {}, recurrency() {} };

            function addProduct(product) {
                // Section 9b: scanning the same product again increments its
                // line rather than adding a second one — but only a line still
                // at the price it arrived with. A line somebody has repriced is
                // a deliberate second price, and folding a scan into it would
                // silently change what that line says.
                const existing = cart.find((l) => l.id === product.id
                    && l.typed === null
                    && l.price === product.purchase_price);

                if (existing) {
                    existing.quantity += 1;
                } else {
                    cart.push({
                        id: product.id,
                        name: product.name,
                        sku: product.sku,
                        unit: product.unit,
                        quantity: 1,
                        // Section 2b: nobody has typed a figure for this line
                        // yet, so its box is drawn from the price below. See
                        // moneyBox for why that distinction matters.
                        typed: null,
                        // Section 9: the purchase cart defaults to the last
                        // purchase price. A first-time purchase has none, so it
                        // starts at 0 and must be typed.
                        price: product.purchase_price,
                    });
                }

                goToScanner();
                render();
            }

            /**
             * Empty both boxes, and put the caret where the next scan goes.
             *
             * Both, because a stale dropdown left open on the other one is a
             * list of things that can still be clicked into a cart nobody is
             * looking at.
             */
            function clearSearch(focusOn = null) {
                searches.forEach((pair) => {
                    pair.input.value = '';
                    pair.results.classList.add('d-none');
                    pair.results.innerHTML = '';
                });

                highlighted = -1;

                (focusOn ?? searchInput).focus();
            }

            /**
             * After an add, the next scan belongs under the line just added.
             *
             * The cart has grown by a row, so the bottom box has moved down the
             * page; it is brought back into view and given the caret. Nothing
             * moves while the cart is empty and both boxes are already on
             * screen together.
             */
            function goToScanner() {
                if (scanner.input === searches[0].input || cart.length === 0) {
                    clearSearch();

                    return;
                }

                clearSearch(scanner.input);
                scanner.input.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }

            async function runSearch(term) {
                // Ordinary stock only: a service has nothing to buy into stock, and a
                // second-hand item is bought once through its own screen.
                const response = await fetch(
                    `{{ route('products.search') }}?kinds=stock&q=${encodeURIComponent(term)}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (! response.ok) return;

                const data = await response.json();

                if (data.exact && data.products.length === 1) {
                    addProduct(data.products[0]);
                    return;
                }

                resultsBox.innerHTML = '';
                highlighted = -1;

                data.products.forEach((product) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                    item.innerHTML = `
                        <span>
                            <span class="fw-medium">${escapeHtml(product.name)}</span>
                            <span class="small text-secondary ms-2" dir="ltr">${escapeHtml(product.sku)}</span>
                        </span>
                        <span class="small text-secondary">${format(product.purchase_price)}</span>`;
                    item.addEventListener('click', () => addProduct(product));
                    resultsBox.appendChild(item);
                });

                resultsBox.classList.toggle('d-none', data.products.length === 0);
            }

            searches.forEach((pair) => {
                pair.input.addEventListener('input', () => {
                    // Typing here makes this the box whose results are shown.
                    searchInput = pair.input;
                    resultsBox = pair.results;

                    clearTimeout(searchTimer);
                    const term = pair.input.value.trim();

                    if (term === '') {
                        pair.results.classList.add('d-none');
                        return;
                    }

                    searchTimer = setTimeout(() => runSearch(term), 150);
                });

                pair.input.addEventListener('focus', () => {
                    searchInput = pair.input;
                    resultsBox = pair.results;
                });

                pair.input.addEventListener('keydown', (event) => {
                const items = [...resultsBox.querySelectorAll('.list-group-item')];

                if (event.key === 'Escape') {
                    searchInput.value = '';
                    resultsBox.classList.add('d-none');
                } else if (event.key === 'ArrowDown' && items.length) {
                    event.preventDefault();
                    highlighted = Math.min(highlighted + 1, items.length - 1);
                    items.forEach((el, i) => el.classList.toggle('active', i === highlighted));
                } else if (event.key === 'ArrowUp' && items.length) {
                    event.preventDefault();
                    highlighted = Math.max(highlighted - 1, 0);
                    items.forEach((el, i) => el.classList.toggle('active', i === highlighted));
                } else if (event.key === 'Enter') {
                    event.preventDefault();

                    if (highlighted >= 0 && items[highlighted]) {
                        items[highlighted].click();
                    } else if (searchInput.value.trim()) {
                        clearTimeout(searchTimer);
                        runSearch(searchInput.value.trim());
                    }
                    }
                });
            });

            cartBody.addEventListener('input', (event) => {
                const index = Number(event.target.dataset.index);
                const line = cart[index];
                if (! line) return;

                const role = event.target.dataset.role;

                if (role === 'qty') {
                    line.quantity = Math.max(1, Number(event.target.value || 1));
                } else if (role === 'price') {
                    line.typed = Math.max(0, Number(event.target.value || 0));
                    line.price = Math.max(0, toBase(line.typed));
                }

                refreshRow(index);
            });

            cartBody.addEventListener('click', (event) => {
                const split = event.target.closest('[data-role="split"]');

                if (split) {
                    const at = Number(split.dataset.index);

                    cart.splice(at + 1, 0, { ...cart[at], quantity: 1 });
                    render();

                    // Straight into the new line's price, since that is what
                    // the second line is for.
                    cartBody.querySelector(`[data-role="price"][data-index="${at + 1}"]`)?.focus();

                    return;
                }

                const button = event.target.closest('[data-role="remove"]');
                if (! button) return;

                cart.splice(Number(button.dataset.index), 1);
                render();
            });

            /**
             * Show the rate only when there is something to convert.
             *
             * ⚠️ Disabled, not merely hidden. A disabled input posts nothing,
             * so an invoice written entirely in the base currency records no
             * exchange rate — which is the truth about it.
             */
            function showRateBox() {
                // Every box on the screen says which currency it is taking.
                document.querySelectorAll('[data-role="mark"]')
                    .forEach((el) => { el.textContent = markOf(invoiceCode()); });

                if (! rateInput) return;

                rateInput.disabled = inBase();
                rateBox.classList.toggle('d-none', inBase());
                rateWarning.classList.toggle('d-none', inBase());

                if (! inBase()) rateOf.textContent = '1 ' + invoiceCode() + ' =';
            }

            if (rateInput) {
                rateInput.addEventListener('input', () => {
                    // Section 6b: warn if the entered rate differs from the
                    // saved one by more than ~10%, usually a typo.
                    const saved = savedRate(invoiceCode());
                    const typed = rate();

                    rateWarning.textContent = saved > 0 && typed > 0
                        && Math.abs(typed - saved) / saved > 0.1
                        ? @json(__('That is more than 10% away from the saved rate. Check for a typo.'))
                        : '';
                    rateWarning.className = rateWarning.textContent ? 'form-text text-warning' : 'form-text';

                    /*
                     * ⚠️ A line somebody TYPED a figure into follows the rate:
                     * $10 at a corrected 1,550 is 15,500 dinars, not 15,000. A
                     * line nobody typed into keeps its base price and is simply
                     * redrawn — that price is what the supplier charged, and a
                     * rate correction is not a reason to move it.
                     */
                    cart.forEach((line) => {
                        if (line.typed !== null) line.price = toBase(line.typed);
                    });

                    discount.reprice();
                    paid.reprice();
                    render();
                });
            }

            if (currencySelect) {
                currencySelect.addEventListener('change', () => {
                    // The box starts at whatever Settings has for the new
                    // currency; a rate typed for the old one means nothing here.
                    if (! inBase()) rateInput.value = savedRate(invoiceCode()) || '';

                    rateWarning.textContent = '';

                    /*
                     * ⚠️ Nothing the invoice is worth changes — only the
                     * currency it is read and typed in. Every price keeps its
                     * base figure and is redrawn converted, which is the whole
                     * point of the choice: pick dollars and the screen is in
                     * dollars. The number somebody typed was so many of the OLD
                     * currency and cannot be read as the new one, so it is
                     * forgotten and the box is drawn from the price itself.
                     */
                    cart.forEach((line) => { line.typed = null; });

                    showRateBox();
                    discount.recurrency();
                    paid.recurrency();
                    render();
                });
            }

            if (paidInput) {
                document.getElementById('pay-full').addEventListener('click', () => {
                    const subtotal = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);

                    // Set in base units, so paying in full pays the exact figure
                    // the books are about to record — never a converted one.
                    paid.set(Math.max(0, subtotal - discount.get()));
                    recalculate();
                });
            }

            document.addEventListener('keydown', (event) => {
                // Not while the keypad is up, or F2 would save the document
                // from under a half-typed price.
                if (document.getElementById('number-pad')?.classList.contains('show')) return;

                if (event.key === 'F2' && ! saveButtons[0].disabled) {
                    event.preventDefault();
                    document.getElementById('purchase-form').requestSubmit();
                }
            });

            showRateBox();
            discount.recurrency();
            paid.recurrency();
            render();

            /*
             * ⚠️ **Leaving with work in the cart asks first** — Soran,
             * 2026-09-26. The snapshot is taken AFTER the first render, so what
             * the screen opened holding is the thing "unchanged" means: on a new
             * sale that is nothing, so any line trips the guard; on an edit it is
             * the document's own lines, so only a real change does; and a held
             * cart restored onto this screen does not, because it is already
             * saved.
             *
             * Quantity and price are in it as well as the product — changing a
             * number is unsaved work exactly as much as adding a line is.
             */
            const snapshot = () => JSON.stringify(
                cart.map((line) => [line.product_id, line.quantity, line.price])
            );

            const pristine = snapshot();

            /*
             * ⚠️ Assigned rather than handed to `appLeaveGuard.watch()`: app.js
             * is a module and the browser defers it, so this inline script runs
             * FIRST and `window.appLeaveGuard` does not exist yet. The guard
             * reads this global when it needs it, so there is no order to get
             * wrong.
             */
            window.appUnsavedWork = () => snapshot() !== pristine;
        })();
    </script>
@endpush

@unless($editing)
    @can('suppliers.create')
        @include('partials.quick-person', [
            'id' => 'supplier',
            'storeRoute' => 'suppliers.store',
            'selectId' => 'supplier_id',
        ])
    @endcan
@endunless

@push('scripts')
    <script>
        /**
         * Putting the cart down.
         *
         * Sent on its own, by fetch, so the cart form is never submitted by
         * accident — the whole point is that this is NOT the purchase. Nothing
         * is written to the books: no document number, no batch, no stock, no
         * ledger row. It waits on this screen until somebody finishes it.
         */
        (() => {
            const button = document.getElementById('hold-cart');

            if (! button) return;

            button.addEventListener('click', async () => {
                const lines = [...document.querySelectorAll('[data-role="qty"]')].map((qty) => {
                    const index = qty.dataset.index;

                    const field = (name) =>
                        document.querySelector(`[name="lines[${index}][${name}]"]`)?.value ?? '';

                    return {
                        product_id: +field('product_id'),
                        quantity: +qty.value,
                        unit_price: +field('unit_price'),
                        // Section 6b: a line typed in another currency must
                        // come back in it, at the amount that was typed.
                        entered_currency: field('entered_currency') || @json($base->code),
                        entered_amount: +field('entered_amount') || null,
                    };
                });

                if (lines.length === 0) return;

                const note = prompt(@json(__('A note, so you know which cart this is (optional)')), '');

                if (note === null) return;

                button.disabled = true;

                try {
                    const response = await fetch(@json(route('held-carts.store')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({
                            type: 'purchase',
                            note: note,
                            lines: lines,
                            party_id: +document.getElementById('supplier_id').value || null,
                        }),
                    });

                    if (! response.ok) throw new Error(@json(__('That could not be saved.')));

                    // ⚠️ Holding IS saving it, so the leave guard must let go
                    // before this navigates — otherwise the one button whose
                    // whole job is to keep the cart would ask whether the
                    // shopkeeper minded losing it.
                    window.appLeaveGuard?.release();

                    window.location = @json(route('purchases.create'));
                } catch (e) {
                    alert(e.message);
                    button.disabled = false;
                }
            });
        })();
    </script>
@endpush
