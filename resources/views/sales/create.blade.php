@extends('layouts.app')

@php
    // The edit screen is this same cart with the sale's lines preloaded and the
    // payment fields hidden — Section 8 keeps payments untouched by an edit.
    $editing = isset($sale);
@endphp

@section('title', $editing ? __('Edit sale') : __('New sale'))
@if($editing)
    @section('subheading', $sale->document_no)
@endif

{{-- Editing came from the sale; a new sale came from the list. --}}
@section('back')
    @if($editing)
        <x-back-link :to="route('sales.show', $sale)" :label="$sale->document_no"
                     permission="sales.view" />
    @else
        <x-back-link :to="route('sales.index')" :label="__('Sales history')"
                     remember="sales" permission="sales.view" />
    @endif
@endsection

@section('content')
    {{--
        Section 9b: "Soran uses this a hundred times a day. Every other page can
        be ordinary; this one has to be fast."

        Layout: product search on top, cart table in the middle, totals panel on
        the right (left in RTL), action buttons fixed at the bottom.
    --}}
    @unless($editing)
        @include('partials.held-carts', [
            'heldCarts' => $heldCarts,
            'resumeRoute' => 'sales.create',
        ])
    @endunless

    <form action="{{ $editing ? route('sales.update', $sale) : route('sales.store') }}"
          method="POST" id="sale-form" data-guard-submit>
        @csrf
        @if($editing) @method('PUT') @endif

        {{-- Carried through so the hold is spent when the sale is saved, and
             not one moment before. --}}
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
                        <table class="table align-middle mb-0 table-cart" id="cart-table">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money" style="width: 7.5rem">{{ __('Quantity') }}</th>
                                <th class="money" style="width: 10rem">{{ __('Unit price') }}</th>
                                <th class="money" style="width: 9rem">{{ __('Total') }}</th>
                                <th style="width: 3rem"></th>
                            </tr>
                            </thead>
                            <tbody id="cart-body"></tbody>
                        </table>
                    </div>

                    <div id="cart-empty" class="text-center text-secondary py-5">
                        <i class="bi bi-cart fs-1 d-block mb-2 opacity-50"></i>
                        {{ __('The cart is empty. Scan a product to begin.') }}
                    </div>
                </div>

                {{-- The same box again, under the last line added. With
                     twenty-five things in the cart the one at the top has
                     scrolled away, and the twenty-sixth scan should not mean
                     scrolling back up to find somewhere to put it. --}}
                <div class="card mt-3">
                    @include('partials.cart-search', ['suffix' => '-bottom'])
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <label for="customer_id" class="form-label">{{ __('Customer') }}</label>
                                @can('customers.create')
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none"
                                            data-bs-toggle="modal" data-bs-target="#new-customer-modal">
                                        <i class="bi bi-plus-lg"></i>{{ __('New') }}
                                    </button>
                                @endcan
                            </div>
                            <select id="customer_id" name="customer_id" class="form-select">
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}"
                                            data-system="{{ $customer->is_system ? '1' : '0' }}"
                                            @selected(old('customer_id', $editing ? $sale->customer_id : $cashCustomer->id) == $customer->id)>
                                        {{ $customer->displayName() }}@if(! $customer->is_system && $customer->balance > 0)
                                            — {{ __('owes :amount', ['amount' => money($customer->balance)]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            {{-- Section 4: the Cash Customer must always be paid
                                 in full — no loan. --}}
                            <div class="form-text" id="cash-customer-note">
                                {{ __('Walk-in buyers use the Cash Customer, which must be paid in full.') }}
                            </div>
                        </div>

                        <div class="mb-3">
                            {{-- The receipt's own currency and rate — Soran,
                                 2026-09-19: "if currency on usd change sale
                                 page to usd, but in sale page have combo to
                                 change again and input to rate".

                                 ⚠️ Only base-currency integers are ever stored.
                                 Each price box below is unnamed and has a
                                 hidden field beside it holding the base figure
                                 the form actually posts — the same rule the
                                 purchase cart has followed since §2b. --}}
                            @if($foreignCurrencies->isNotEmpty())
                                @php
                                    $chosenCode = old('document_currency', $documentCurrency);
                                @endphp
                                <div class="row g-2 mb-3">
                                    <div class="col-7">
                                        <label for="document_currency" class="form-label">{{ __('Receipt currency') }}</label>
                                        <select id="document_currency" name="document_currency" class="form-select"
                                                data-meta="{{ json_encode($currencyMeta) }}"
                                                data-base="{{ $base->code }}">
                                            <option value="{{ $base->code }}" @selected($chosenCode === $base->code)>
                                                {{ $base->name }} ({{ $base->code }})
                                            </option>
                                            @foreach($foreignCurrencies as $currency)
                                                <option value="{{ $currency->code }}" @selected($chosenCode === $currency->code)>
                                                    {{ $currency->name }} ({{ $currency->code }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-5">
                                        <label for="exchange_rate" class="form-label">{{ __('Rate') }}</label>
                                        <input id="exchange_rate" type="number" step="1" min="1" dir="ltr"
                                               name="exchange_rate" class="form-control text-end"
                                               value="{{ old('exchange_rate', $documentRate ?: '') }}"
                                               @disabled($chosenCode === $base->code)
                                               data-english-digits>
                                    </div>
                                </div>
                            @endif

                            <label for="sale_date" class="form-label">{{ __('Date') }}</label>
                            <input id="sale_date" type="date" name="sale_date" class="form-control"
                                   value="{{ old('sale_date', $editing ? $sale->sale_date->toDateString() : today()->toDateString()) }}" required>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        {{-- Section 9b: "Show the running total large and always
                             visible. It is the number Soran reads out to the
                             customer." --}}
                        <div class="text-secondary small">{{ __('Total') }}</div>
                        <div class="running-total" id="running-total" data-role="running-total">0</div>

                        {{-- The figure written out, the oldest anti-fraud device
                             on an invoice: a digit can be changed with a pen and
                             a sentence cannot. Written in the browser because
                             the total moves on every keystroke, from the word
                             lists the server hands over. --}}
                        <div class="small text-secondary mb-3" id="running-total-words"
                             data-words="{{ json_encode(App\Support\AmountInWords::vocabulary(), JSON_UNESCAPED_UNICODE) }}"></div>

                        @if($editing)
                            <div class="alert alert-secondary py-2 small mb-0">
                                {{ __('Payments are not changed by an edit. The new total must still cover the :paid already paid.', ['paid' => money($sale->amountPaid())]) }}
                            </div>
                        @else
                        <div class="mb-3">
                            <label for="amount_paid" class="form-label">{{ __('Paid now') }}</label>
                            <div class="input-group">
                                <input id="amount_paid" type="number" step="1" min="0" name="amount_paid"
                                       class="form-control text-end" dir="ltr" value="0">
                                <button type="button" class="btn btn-outline-secondary" id="pay-full">
                                    {{ __('Full') }}
                                </button>
                            </div>
                            <div class="form-text" id="due-note"></div>
                        </div>

                        <div class="mb-3">
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

                {{--
                    ⚠️ **Not sticky, and it used to be.**

                    `position-sticky; bottom: 1rem` was written for Section 9b's
                    *"action buttons fixed at the bottom so they never scroll
                    away"*. A bottom-sticky element is pinned to the bottom of
                    the window whenever its own place in the page is below the
                    fold — and it paints over whatever is there, because sticky
                    keeps its space where it was and only draws somewhere else.

                    What that meant, measured at 1280×800 on an EMPTY cart, the
                    state this screen opens in: the buttons drew at y 644–784,
                    the Method dropdown sits at 764–802, and
                    `document.elementFromPoint` over the middle of Method
                    returned the button block. The field was not merely covered,
                    it could not be clicked, until somebody scrolled.

                    There is no version of bottom-sticky that avoids this: any
                    element pinned to the bottom of the window lands on whatever
                    the last field is. So it goes — and it costs almost nothing,
                    because F2 already saves from anywhere on this page and the
                    hint under the scanner says so.
                --}}
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" id="save-sale" disabled
                            data-role="save" data-submitting-text="{{ __('Saving…') }}">
                        {{ $editing ? __('Save changes') : __('Save sale') }} <kbd class="ms-1">F2</kbd>
                    </button>
                    @unless($editing)
                        {{-- Put it down without finishing it. Nothing is written
                             to the books: no number, no batch, no stock moved. --}}
                        <button type="button" class="btn btn-outline-secondary" id="hold-cart" disabled>
                            <i class="bi bi-pause-circle me-1"></i>{{ __('Hold this cart') }}
                        </button>
                    @endunless

                    <a href="{{ $editing ? route('sales.show', $sale) : route('sales.index') }}"
                       class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
        {{-- ⚠️ The till bar — a phone only, and inside the form on purpose.

             On a laptop the totals panel sits beside the cart and the running
             total is never out of sight. On a phone that panel stacks under the
             cart: with four lines scanned, Save was about fourteen hundred
             pixels below the scanner, and the total the shopkeeper reads out to
             the customer was down there with it.

             Inside the `<form>` rather than attached to it with `form="…"`,
             because that is what makes the hold-to-save guard find it — app.js
             walks `form.querySelectorAll`, and a button outside the form is
             never walked. So this Save holds for two seconds like every other
             Save in the shop, with no change to the guard at all.

             No F2 here: a phone has no F2 key. --}}
        <div class="app-till-bar d-md-none no-print">
            <div class="min-w-0">
                <div class="app-till-bar-label">{{ __('Total') }}</div>
                <div class="app-till-bar-total money" data-role="running-total">0</div>
            </div>

            <button type="submit" class="btn btn-primary" disabled
                    data-role="save" data-submitting-text="{{ __('Saving…') }}">
                {{ $editing ? __('Save changes') : __('Save sale') }}
            </button>
        </div>
    </form>

    @unless($editing)
        @can('customers.create')
            @include('partials.quick-person', [
                'id' => 'customer',
                'storeRoute' => 'customers.store',
                'selectId' => 'customer_id',
            ])
        @endcan
    @endunless
@endsection

@push('scripts')
    <script>
        // Section 9b: a barcode scanner IS a keyboard. Focus sits in the search
        // box by default and returns there after every add, so a scan just works
        // with no clicking.
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
            const totalEls = document.querySelectorAll('[data-role="running-total"]');
            const wordsEl = document.getElementById('running-total-words');

            /*
             * The total in words, written here rather than fetched.
             *
             * The figure moves on every keystroke, so asking the server would
             * be a request per character. The server hands over the vocabulary
             * once — in the reader's language — and this does the joining. The
             * same joining as App\Support\AmountInWords, which is what the
             * printed invoice uses; AmountInWordsTest holds the two to the same
             * answers.
             */
            const vocab = (() => {
                try { return JSON.parse(wordsEl.dataset.words); } catch { return null; }
            })();

            const inWords = (n) => {
                if (! vocab || ! Number.isFinite(n) || n < 0 || n > vocab.max) return '';
                if (n === 1) return vocab.oneDinar;

                const join = (parts) => parts.filter(Boolean).reduce(
                    (carry, next) => (carry === null
                        ? next
                        : vocab.join.split('{f}').join(carry).split('{s}').join(next)),
                    null,
                ) ?? '';

                const underThousand = (v) => {
                    const parts = [];
                    if (v >= 100) { parts.push(vocab.hundreds[Math.floor(v / 100)]); v %= 100; }
                    if (v >= 20) {
                        const t = vocab.tens[Math.floor(v / 10)];
                        parts.push(v % 10 === 0
                            ? t
                            : vocab.tensUnits.split('{t}').join(t).split('{u}').join(vocab.units[v % 10]));
                        v = 0;
                    }
                    if (v >= 10) { parts.push(vocab.teens[v - 10]); v = 0; }
                    if (v > 0) { parts.push(vocab.units[v]); }
                    return join(parts);
                };

                if (n === 0) return vocab.currency.split('__').join(vocab.units[0]);

                const parts = [];
                let rest = n;

                [[1e9, 2], [1e6, 1], [1e3, 0]].forEach(([size, scale]) => {
                    if (rest >= size) {
                        const count = Math.floor(rest / size);
                        parts.push(
                            count === 1 ? vocab.ones[scale]
                                : count === 2 ? vocab.twos[scale]
                                    : underThousand(count) + ' ' + vocab.scales[scale],
                        );
                        rest %= size;
                    }
                });

                if (rest > 0) parts.push(underThousand(rest));

                return vocab.currency.split('__').join(join(parts));
            };

            const showTotal = (total) => {
                // Said in two places on a phone — the panel and the till bar —
                // and in one on a laptop. Both read the same number from here.
                totalEls.forEach((el) => { el.textContent = format(total); });
                if (wordsEl) wordsEl.textContent = inWords(Math.round(total));
            };
            const paidInput = document.getElementById('amount_paid');
            const dueNote = document.getElementById('due-note');
            // Same button, twice, for the same reason as the total. Every
            // element wearing the role, rather than one id, so the till bar
            // cannot fall out of step with the panel.
            const saveButtons = document.querySelectorAll('[data-role="save"]');
            const customerSelect = document.getElementById('customer_id');

            // Section 8: an edit starts from the sale's current lines.
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

                    // Section 9b: qty and price are edited in the row. Tapping
                    // either opens the keypad, which a finger can use on a
                    // touchscreen and a keyboard can drive just as fast.
                    row.innerHTML = `
                        <td class="cart-cell-product">
                            <div class="fw-medium">
                                ${escapeHtml(line.name)}
                                ${line.kind === 'service'
                                    ? `<span class="badge text-bg-light">${@json(__('Service'))}</span>`
                                    : line.kind === 'used'
                                        ? `<span class="badge text-bg-light">${@json(__('Second-hand'))}</span>`
                                        : ''}
                            </div>
                            {{-- The SKU and what is left on the shelf, together on
                                 one line under the name. The stock note used to
                                 live under the quantity box, which pushed that box
                                 above the price box beside it and left the two
                                 inputs on different levels — visible in every
                                 language, and the thing Soran marked first. --}}
                            <div class="small text-secondary d-flex flex-wrap align-items-center gap-2">
                                <span dir="ltr">${escapeHtml(line.sku)}</span>
                                ${line.kind === 'service' ? '' : `
                                    <span class="opacity-50" aria-hidden="true">&bull;</span>
                                    <span class="${line.stock > 0 || (line.rebuildable ?? 0) > 0 ? '' : 'text-danger fw-semibold'}">${format(line.stock)} ${escapeHtml(line.unit ?? '')} ${@json(__('in stock'))}</span>
                                    ${/* ⚠️ Not red, and said in words. A bundle
                                          taken apart reads zero and is about to
                                          be put back together at the till — a
                                          shopkeeper who sees that in red assumes
                                          the sale is about to be refused. */ ''}
                                    ${/* ⚠️ Always drawn, hidden when it does
                                          not apply, because the quantity box
                                          beside it changes this sentence and
                                          `refreshRow` redraws cells rather
                                          than the row — a note conjured by the
                                          template only is a note that never
                                          appears when somebody types a 3. */ ''}
                                    <span class="${(line.rebuildable ?? 0) > 0 && line.stock < line.quantity ? '' : 'd-none'}"
                                          data-role="rebuild-note" data-index="${index}">
                                        <span class="opacity-50" aria-hidden="true">&bull;</span>
                                        <span class="text-info">${@json(__('will be put back together'))}</span>
                                    </span>`}
                            </div>
                            ${line.condition ? `<div class="small text-secondary">${escapeHtml(line.condition)}</div>` : ''}
                            <div class="small text-warning ${line.belowCost ? '' : 'd-none'}" data-role="below-cost">
                                <i class="bi bi-exclamation-triangle"></i>
                                ${@json(__('Below cost: this unit cost'))} ${format(line.cost ?? 0)}
                            </div>
                            <input type="hidden" name="lines[${index}][product_id]" value="${line.id}">
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
                            {{-- ⚠️ The visible box is the SCREEN'S; the hidden
                                 one beside it is the BOOKS'. Whatever currency
                                 this receipt is written in, only a base-currency
                                 integer is ever posted — §2b, and the same
                                 shape the purchase cart uses. --}}
                            <input type="number" min="0" step="any" dir="ltr"
                                   class="form-control form-control-sm text-end"
                                   value="${line.price}"
                                   data-role="price" data-index="${index}"
                                   data-numpad="${escapeHtml(line.name)}">
                            <input type="hidden" name="lines[${index}][unit_price]" value="${line.price}"
                                   data-role="price-base" data-index="${index}">
                            <input type="hidden" name="lines[${index}][entered_currency]"
                                   data-role="price-code" data-index="${index}">
                            <input type="hidden" name="lines[${index}][entered_amount]"
                                   data-role="price-typed" data-index="${index}">
                            <div class="small text-secondary text-end d-none" data-role="price-base-note" data-index="${index}"></div>
                        </td>
                        <td class="money fw-semibold cart-cell-total">${format(line.quantity * line.price)}</td>
                        <td class="cart-cell-actions">
                            <div class="btn-group btn-group-sm">
                                {{-- Section 4: "one sale can list the same
                                     product on two lines at two prices", which
                                     is what reference_item_id on the movements
                                     is for. Scanning again adds to the line, so
                                     this is how the second one is asked for. --}}
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

                const total = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                showTotal(total);
                updateDue(total);
            }

            function updateDue(total) {
                // An edit has no payment fields — Section 8 leaves payments alone.
                if (! paidInput) return;

                const paid = Number(paidInput.value || 0);
                const due = total - paid;
                dueNote.textContent = due > 0
                    ? @json(__('Remaining on account:')) + ' ' + format(due)
                    : @json(__('Paid in full'));
            }

            /*
             * ⚠️ One of a bundle and its pieces at a time — Soran, 2026-09-24:
             * *"if sale or add to card one of 3 main or lines should lock
             * other"*.
             *
             * The stock refuses this anyway, at the end, once the rebuild has
             * eaten the pieces the other line wanted — but at a till that is
             * far too late: the customer is waiting and the cart has to be
             * unpicked. Said here instead, the moment it is asked for.
             */
            function clashesWithCart(product) {
                const mine = product.piece_ids ?? [];

                // Adding a bundle whose piece is already in the cart.
                const takenPiece = cart.find((line) => mine.includes(line.id));

                if (takenPiece) {
                    return @json(__(':piece is already in this sale, and :whole is made of it. Take one of them out first.'))
                        .replace(':piece', takenPiece.name)
                        .replace(':whole', product.name);
                }

                // Adding a piece of a bundle that is already in the cart.
                const takenWhole = cart.find((line) => (line.pieceIds ?? []).includes(product.id));

                return takenWhole
                    ? @json(__(':whole is already in this sale and is made of :piece. Take one of them out first.'))
                        .replace(':whole', takenWhole.name)
                        .replace(':piece', product.name)
                    : null;
            }

            /**
             * How many of this the shopkeeper agrees to have on this line.
             *
             * What was asked for when nothing has to be built and when they say
             * yes; the most that could ever be handed over when more than that
             * was asked; zero when they say no.
             *
             * ⚠️ Silent in every ordinary case, which is the point: it asks
             * only when the shelf cannot cover what this line now wants and
             * there are pieces to make up the difference. A question on every
             * scan would be answered without being read within a day.
             *
             * ⚠️ Asked from two places, because there are two ways to want a
             * second one: scanning it again, and typing a bigger number into
             * the quantity box. The second went through without a word until
             * this was written.
             */
            function agreedQuantity({ name, wanted, stock, rebuildable, pieces, agreed = 0 }) {
                if (wanted <= stock || rebuildable < 1 || wanted <= agreed) {
                    return wanted;
                }

                /*
                 * ⚠️ More than the shelf and the pieces together could ever
                 * come to, so the honest answer is the ceiling and not a
                 * question. The save would otherwise refuse it saying **"Not
                 * enough stock: 1 available"** — true of the shelf, and wrong
                 * about this shop, which can hand over two. A shopkeeper told
                 * one would set the line to one and never learn about the
                 * second.
                 */
                const ceiling = stock + rebuildable;

                if (wanted > ceiling) {
                    window.alert(@json(__('Only :count :product can be sold: :stock on the shelf and :more that can be put back together.'))
                        .replace(':count', format(ceiling))
                        .replace(':product', name)
                        .replace(':stock', format(stock))
                        .replace(':more', format(rebuildable)));

                    return ceiling;
                }

                // Two sentences, because "there is none" is a lie when there
                // are two and a third was asked for.
                const question = (stock > 0
                    ? @json(__('Only :count :product on the shelf. Put :pieces back together for the rest?'))
                        .replace(':count', format(stock))
                    : @json(__('There is no :product on the shelf. Put :pieces back together to sell it?')))
                    .replace(':product', name)
                    .replace(':pieces', pieces.join(', '));

                return window.confirm(question) ? wanted : 0;
            }

            function addProduct(product) {
                const clash = clashesWithCart(product);

                if (clash) {
                    window.alert(clash);
                    goToScanner();

                    return;
                }

                // Section 9b: "Scanning the same product again increments its
                // line rather than adding a second one." Two lines for one
                // product at different prices is entered deliberately, by
                // editing the price on an existing line.
                const existing = cart.find((l) => l.id === product.id && l.price === product.sale_price);

                /*
                 * ⚠️ Asked before it happens, never after — Soran chose "yes,
                 * but ask me first". Putting a bundle back together takes its
                 * pieces off the shelf, and a shopkeeper who scanned a barcode
                 * should not discover that from the stock report.
                 *
                 * ⚠️ Against the quantity this line is about to REACH, not
                 * against one. Two on the shelf and a third scanned is a
                 * rebuild as surely as a first scanned with none, and asking
                 * only about the first would have let the commonest of the two
                 * through in silence.
                 */
                const wanted = (existing?.quantity ?? 0) + 1;

                if (agreedQuantity({
                    name: product.name,
                    wanted,
                    stock: product.quantity ?? 0,
                    rebuildable: product.rebuildable ?? 0,
                    pieces: product.pieces ?? [],
                    agreed: existing?.agreed ?? 0,
                }) < wanted) {
                    goToScanner();

                    return;
                }

                if (existing) {
                    existing.quantity += 1;
                    existing.agreed = Math.max(existing.agreed ?? 0, wanted);
                } else {
                    cart.push({
                        // How far this line has already been agreed to be put
                        // back together, so raising it asks again and leaving
                        // it where it is does not.
                        agreed: wanted,
                        id: product.id,
                        name: product.name,
                        sku: product.sku,
                        unit: product.unit,
                        quantity: 1,
                        price: product.sale_price,
                        stock: product.quantity,
                        kind: product.kind,
                        condition: product.condition_note,
                        cost: product.next_batch_cost,
                        belowCost: product.next_batch_cost !== null && product.sale_price < product.next_batch_cost,

                        // What this line is made of, so a piece of it cannot be
                        // added to the same cart.
                        pieceIds: product.piece_ids ?? [],
                        pieces: product.pieces ?? [],
                        rebuildable: product.rebuildable ?? 0,
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

                const target = focusOn ?? searchInput;

                target.focus();
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
                // ⚠️ `rebuildable=1` is the till asking a question only the
                // till asks: what could be put back together to sell. A
                // purchase or an adjustment is not rebuilding anything, and the
                // work is skipped for them.
                const response = await fetch(`{{ route('products.search') }}?q=${encodeURIComponent(term)}&rebuildable=1`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (! response.ok) return;

                const data = await response.json();

                // An exact barcode or SKU match adds straight to the cart at
                // qty 1; a partial name match shows a dropdown.
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
                        <span class="small">
                            ${product.kind === 'service'
                                ? `<span class="text-secondary me-2">${@json(__('service'))}</span>`
                                : `<span class="text-secondary me-2">${format(product.quantity)} ${escapeHtml(product.unit ?? '')} ${@json(__('in stock'))}${
                                    (product.rebuildable ?? 0) > 0
                                        ? ` · ${@json(__('+:count if put back together'))}`.replace(':count', format(product.rebuildable))
                                        : ''}</span>`}
                            <span class="fw-semibold">${format(product.sale_price)}</span>
                        </span>`;
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
                    clearSearch();
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
                    } else {
                        clearTimeout(searchTimer);
                        const term = searchInput.value.trim();
                        if (term) runSearch(term);
                    }
                    }
                });
            });

            /**
             * Update one row's derived figures without rebuilding the table.
             *
             * render() replaces cartBody.innerHTML, which destroys whichever
             * input has focus — so calling it from an 'input' handler meant a
             * price could never be more than one digit long: the field vanished
             * after the first keystroke.
             */
            function refreshRow(index) {
                const line = cart[index];
                const row = cartBody.querySelectorAll('tr')[index];
                if (! line || ! row) return;

                row.querySelector('.money').textContent = format(line.quantity * line.price);
                row.querySelector('[data-role="below-cost"]').classList.toggle('d-none', ! line.belowCost);
                row.querySelector('[data-role="rebuild-note"]')
                    ?.classList.toggle('d-none', ! ((line.rebuildable ?? 0) > 0 && line.stock < line.quantity));

                recalc();
            }

            /**
             * The running total, always from the BASE figures.
             *
             * Its own function because changing the receipt's currency or its
             * rate has to redo it without there being one row to refresh.
             */
            function recalc() {
                const total = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);

                showTotal(total);
                updateDue(total);
            }

            /**
             * The receipt's own currency — Soran, 2026-09-19.
             *
             * ⚠️ **`line.price` is ALWAYS the base figure.** The visible box
             * holds whatever this receipt is written in; the hidden field
             * beside it, the totals, the amount due and the held cart all read
             * the base one. Nothing about what the shop is owed depends on
             * which currency somebody chose to type in.
             */
            const docSelect = document.getElementById('document_currency');
            const rateBox = document.getElementById('exchange_rate');
            const currencyMeta = docSelect ? JSON.parse(docSelect.dataset.meta) : {};
            const baseCode = docSelect ? docSelect.dataset.base : null;

            function docCurrency() {
                if (! docSelect) return null;

                const code = docSelect.value;

                if (! code || code === baseCode) return null;

                return currencyMeta[code] ?? null;
            }

            function docRate() {
                return Math.max(1, Number(rateBox?.value || 0));
            }

            /** What somebody typed, read back as base units. */
            function toBase(typed) {
                return docCurrency() ? Math.round(typed * docRate()) : Math.round(typed);
            }

            /** A base figure, written in the currency this receipt names. */
            function fromBase(base) {
                const currency = docCurrency();

                if (! currency) return base;

                return Number((base / docRate()).toFixed(currency.decimals));
            }

            /** The three hidden fields and the note under a price box. */
            function writePrice(index) {
                const line = cart[index];
                if (! line) return;

                const currency = docCurrency();
                const at = (role) => cartBody.querySelector(`[data-role="${role}"][data-index="${index}"]`);

                const base = at('price-base');
                const code = at('price-code');
                const typed = at('price-typed');
                const note = at('price-base-note');

                if (base) base.value = line.price;

                if (code) code.value = currency && line.typed != null ? currency.code : '';

                // ⚠️ Scaled by THIS currency's own minor units, not by a
                // hundred: a yen has none, and dividing it by 100 on the way
                // back would show ¥5 for ¥500 — §2b learned that once already.
                if (typed) {
                    typed.value = currency && line.typed != null
                        ? Math.round(line.typed * currency.minorPerMajor)
                        : '';
                }

                if (note) {
                    note.textContent = currency ? `= ${format(line.price)}` : '';
                    note.classList.toggle('d-none', ! currency);
                }
            }

            /** Redraw every visible price box in the currency now chosen. */
            function redrawPrices() {
                const currency = docCurrency();

                if (rateBox) rateBox.disabled = ! currency;

                cart.forEach((line, index) => {
                    const box = cartBody.querySelector(`[data-role="price"][data-index="${index}"]`);

                    if (box) box.value = fromBase(line.price);

                    writePrice(index);
                });
            }

            docSelect?.addEventListener('change', () => {
                const currency = docCurrency();

                // Opening the rate box on the shop's saved rate, so nobody has
                // to remember today's before they can type a price.
                if (currency && ! Number(rateBox.value)) rateBox.value = currency.rate;

                /*
                 * ⚠️ The untouched-field rule, §2b. A line somebody typed a
                 * foreign figure into follows the currency; a line that still
                 * holds its base price is merely REDRAWN in it. Re-reading a
                 * redrawn figure back would move money on a screen nobody
                 * touched.
                 */
                cart.forEach((line) => { if (! currency) line.typed = null; });

                redrawPrices();
                recalc();
            });

            rateBox?.addEventListener('input', () => {
                const currency = docCurrency();

                if (! currency) return;

                // Only the lines actually typed in this currency move with it.
                cart.forEach((line, index) => {
                    if (line.typed == null) return;

                    line.price = toBase(line.typed);
                    refreshRow(index);
                });

                redrawPrices();
                recalc();
            });

            cartBody.addEventListener('input', (event) => {
                const index = Number(event.target.dataset.index);
                const line = cart[index];
                if (! line) return;

                if (event.target.dataset.role === 'qty') {
                    line.quantity = Math.max(1, Number(event.target.value || 1));
                } else if (event.target.dataset.role === 'price') {
                    const typed = Math.max(0, Number(event.target.value || 0));

                    // What they typed, and what the books take from it.
                    line.typed = docCurrency() ? typed : null;
                    line.price = Math.max(0, toBase(typed));
                    writePrice(index);
                    // Section 9b: below-cost warns, never blocks — Soran may sell
                    // below cost deliberately for clearance or damaged goods.
                    line.belowCost = line.cost !== null && line.price < line.cost;
                }

                refreshRow(index);
            });

            /*
             * ⚠️ Typing a bigger number is the same decision as scanning one
             * more, and it used to go through without a word.
             *
             * On `change` rather than `input`, or typing "10" would ask at the
             * "1" — and `change` is also what the number pad's OK dispatches,
             * so the phone way in is the same one. The pad hides itself and
             * moves on to the price box a moment later; the question is asked
             * over it and answered before any of that, which is the order a
             * shopkeeper reads it in anyway.
             */
            cartBody.addEventListener('change', (event) => {
                if (event.target.dataset.role !== 'qty') return;

                const index = Number(event.target.dataset.index);
                const line = cart[index];
                if (! line) return;

                const agreed = agreedQuantity({
                    name: line.name,
                    wanted: line.quantity,
                    stock: line.stock,
                    rebuildable: line.rebuildable ?? 0,
                    pieces: line.pieces ?? [],
                    agreed: line.agreed ?? 0,
                });

                // Said no: back to what the shelf holds, or to what was already
                // agreed if that is more — never back to one, which would throw
                // away a quantity nobody objected to.
                line.quantity = agreed > 0
                    ? agreed
                    : Math.max(1, line.agreed ?? 0, Math.min(line.quantity, line.stock));

                line.agreed = Math.max(line.agreed ?? 0, line.quantity);

                event.target.value = line.quantity;
                refreshRow(index);
            });

            cartBody.addEventListener('click', (event) => {
                const split = event.target.closest('[data-role="split"]');

                if (split) {
                    // A copy of the line, right under it, at one unit. The
                    // price is the same until somebody changes it — which is
                    // the whole reason for asking for a second line.
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
                searchInput.focus();
            });

            if (paidInput) {
                paidInput.addEventListener('input', () => {
                    updateDue(cart.reduce((sum, l) => sum + l.quantity * l.price, 0));
                });

                document.getElementById('pay-full').addEventListener('click', () => {
                    paidInput.value = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                    paidInput.dispatchEvent(new Event('input'));
                });

                // The Cash Customer must be paid in full, so paying in full is
                // pre-filled when it is selected.
                customerSelect.addEventListener('change', () => {
                    if (customerSelect.selectedOptions[0]?.dataset.system === '1') {
                        document.getElementById('pay-full').click();
                    }
                });
            }

            document.addEventListener('keydown', (event) => {
                // Not while the keypad is up, or F2 would save the document
                // from under a half-typed price.
                if (document.getElementById('number-pad')?.classList.contains('show')) return;

                if (event.key === 'F2' && ! saveButtons[0].disabled) {
                    event.preventDefault();
                    document.getElementById('sale-form').requestSubmit();
                }
            });

            render();
        })();
    </script>
@endpush

@push('scripts')
    <script>
        /**
         * Putting the cart down.
         *
         * Sent on its own, by fetch, so the cart form is never submitted by
         * accident — the whole point is that this is NOT the sale. Nothing is
         * written to the books: no document number, no batch, no stock moved,
         * no ledger row. The cart simply waits on this screen until somebody
         * finishes it or throws it away.
         */
        (() => {
            const button = document.getElementById('hold-cart');

            if (! button) return;

            button.addEventListener('click', async () => {
                const lines = [...document.querySelectorAll('[data-role="qty"]')].map((qty) => {
                    const index = qty.dataset.index;

                    return {
                        product_id: +document.querySelector(`[name="lines[${index}][product_id]"]`).value,
                        quantity: +qty.value,
                        // The base figure, never the box somebody typed a
                        // dollar into — a held cart comes back on any screen.
                        unit_price: +document.querySelector(`[name="lines[${index}][unit_price]"]`).value,
                    };
                });

                if (lines.length === 0) return;

                const note = prompt(@json(__('A note, so you know which cart this is (optional)')), '');

                // Cancel on the prompt means cancel, not an empty note.
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
                            type: 'sale',
                            note: note,
                            lines: lines,
                            party_id: +document.getElementById('customer_id').value || null,
                        }),
                    });

                    if (! response.ok) throw new Error(@json(__('That could not be saved.')));

                    // Back to a clean screen, with the held cart now waiting on it.
                    window.location = @json(route('sales.create'));
                } catch (e) {
                    alert(e.message);
                    button.disabled = false;
                }
            });
        })();
    </script>
@endpush
