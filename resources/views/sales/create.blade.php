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
                        <table class="table align-middle mb-0" id="cart-table">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money" style="width: 7rem">{{ __('Quantity') }}</th>
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
                        <div class="running-total mb-3" id="running-total">0</div>

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

                {{-- Section 9b: action buttons fixed at the bottom so they never
                     scroll away. --}}
                <div class="d-grid gap-2 position-sticky" style="bottom: 1rem">
                    <button type="submit" class="btn btn-primary btn-lg" id="save-sale" disabled
                            data-submitting-text="{{ __('Saving…') }}">
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
            const totalEl = document.getElementById('running-total');
            const paidInput = document.getElementById('amount_paid');
            const dueNote = document.getElementById('due-note');
            const saveButton = document.getElementById('save-sale');
            const customerSelect = document.getElementById('customer_id');

            // Section 8: an edit starts from the sale's current lines.
            const cart = @json($cartLines ?? []);

            let highlighted = -1;
            let searchTimer = null;

            const format = (n) => new Intl.NumberFormat('en-US').format(Math.round(n));

            function render() {
                cartBody.innerHTML = '';

                cart.forEach((line, index) => {
                    const row = document.createElement('tr');

                    // Section 9b: qty and price are edited in the row. Tapping
                    // either opens the keypad, which a finger can use on a
                    // touchscreen and a keyboard can drive just as fast.
                    row.innerHTML = `
                        <td>
                            <div class="fw-medium">
                                ${escapeHtml(line.name)}
                                ${line.kind === 'service'
                                    ? `<span class="badge text-bg-light">@json(__('Service'))</span>`
                                    : line.kind === 'used'
                                        ? `<span class="badge text-bg-light">@json(__('Second-hand'))</span>`
                                        : ''}
                            </div>
                            <div class="small text-secondary" dir="ltr">${escapeHtml(line.sku)}</div>
                            ${line.condition ? `<div class="small text-secondary">${escapeHtml(line.condition)}</div>` : ''}
                            <div class="small text-warning ${line.belowCost ? '' : 'd-none'}" data-role="below-cost">
                                <i class="bi bi-exclamation-triangle"></i>
                                @json(__('Below cost: this unit cost')) ${format(line.cost ?? 0)}
                            </div>
                            <input type="hidden" name="lines[${index}][product_id]" value="${line.id}">
                        </td>
                        <td>
                            <input type="number" min="1" step="1" dir="ltr"
                                   class="form-control form-control-sm text-end"
                                   name="lines[${index}][quantity]" value="${line.quantity}"
                                   data-role="qty" data-index="${index}"
                                   data-numpad="@json(__('Quantity'))" data-numpad-min="1">
                            ${line.kind === 'service'
                                ? ''
                                : `<div class="small text-secondary text-end">${format(line.stock)} @json(__('in stock'))</div>`}
                        </td>
                        <td>
                            <input type="number" min="0" step="1" dir="ltr"
                                   class="form-control form-control-sm text-end"
                                   name="lines[${index}][unit_price]" value="${line.price}"
                                   data-role="price" data-index="${index}"
                                   data-numpad="${escapeHtml(line.name)}">
                        </td>
                        <td class="money fw-semibold">${format(line.quantity * line.price)}</td>
                        <td>
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
                saveButton.disabled = cart.length === 0;

                // Nothing to put down until something is in it.
                const hold = document.getElementById('hold-cart');
                if (hold) hold.disabled = cart.length === 0;

                const total = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                totalEl.textContent = format(total);
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

            function addProduct(product) {
                // Section 9b: "Scanning the same product again increments its
                // line rather than adding a second one." Two lines for one
                // product at different prices is entered deliberately, by
                // editing the price on an existing line.
                const existing = cart.find((l) => l.id === product.id && l.price === product.sale_price);

                if (existing) {
                    existing.quantity += 1;
                } else {
                    cart.push({
                        id: product.id,
                        name: product.name,
                        sku: product.sku,
                        quantity: 1,
                        price: product.sale_price,
                        stock: product.quantity,
                        kind: product.kind,
                        condition: product.condition_note,
                        cost: product.next_batch_cost,
                        belowCost: product.next_batch_cost !== null && product.sale_price < product.next_batch_cost,
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
                const response = await fetch(`{{ route('products.search') }}?q=${encodeURIComponent(term)}`, {
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
                                ? `<span class="text-secondary me-2">@json(__('service'))</span>`
                                : `<span class="text-secondary me-2">${format(product.quantity)} @json(__('in stock'))</span>`}
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

                const total = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                totalEl.textContent = format(total);
                updateDue(total);
            }

            cartBody.addEventListener('input', (event) => {
                const index = Number(event.target.dataset.index);
                const line = cart[index];
                if (! line) return;

                if (event.target.dataset.role === 'qty') {
                    line.quantity = Math.max(1, Number(event.target.value || 1));
                } else if (event.target.dataset.role === 'price') {
                    line.price = Math.max(0, Number(event.target.value || 0));
                    // Section 9b: below-cost warns, never blocks — Soran may sell
                    // below cost deliberately for clearance or damaged goods.
                    line.belowCost = line.cost !== null && line.price < line.cost;
                }

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

                if (event.key === 'F2' && ! saveButton.disabled) {
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
