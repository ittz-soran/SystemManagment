@extends('layouts.app')

@section('title', __('New sale'))

@section('content')
    {{--
        Section 9b: "Soran uses this a hundred times a day. Every other page can
        be ordinary; this one has to be fast."

        Layout: product search on top, cart table in the middle, totals panel on
        the right (left in RTL), action buttons fixed at the bottom.
    --}}
    <form action="{{ route('sales.store') }}" method="POST" id="sale-form" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <label for="product-search" class="form-label small">
                            {{ __('Scan a barcode, or search by name or SKU') }}
                        </label>
                        <input id="product-search" type="text" class="form-control form-control-lg"
                               autocomplete="off" autofocus
                               placeholder="{{ __('Scan or type…') }}">
                        <div id="search-results" class="list-group mt-2 d-none"></div>
                        <div class="form-text">
                            {{ __('Enter adds · ↑ ↓ move · Esc clears · F2 saves') }}
                        </div>
                    </div>
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
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">{{ __('Customer') }}</label>
                            <select id="customer_id" name="customer_id" class="form-select">
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}"
                                            data-system="{{ $customer->is_system ? '1' : '0' }}"
                                            @selected(old('customer_id', $cashCustomer->id) == $customer->id)>
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
                                   value="{{ old('sale_date', today()->toDateString()) }}" required>
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
                    </div>
                </div>

                {{-- Section 9b: action buttons fixed at the bottom so they never
                     scroll away. --}}
                <div class="d-grid gap-2 position-sticky" style="bottom: 1rem">
                    <button type="submit" class="btn btn-primary btn-lg" id="save-sale" disabled
                            data-submitting-text="{{ __('Saving…') }}">
                        {{ __('Save sale') }} <kbd class="ms-1">F2</kbd>
                    </button>
                    <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        // Section 9b: a barcode scanner IS a keyboard. Focus sits in the search
        // box by default and returns there after every add, so a scan just works
        // with no clicking.
        (() => {
            const searchInput = document.getElementById('product-search');
            const resultsBox = document.getElementById('search-results');
            const cartBody = document.getElementById('cart-body');
            const cartEmpty = document.getElementById('cart-empty');
            const totalEl = document.getElementById('running-total');
            const paidInput = document.getElementById('amount_paid');
            const dueNote = document.getElementById('due-note');
            const saveButton = document.getElementById('save-sale');
            const customerSelect = document.getElementById('customer_id');

            const cart = [];
            let highlighted = -1;
            let searchTimer = null;

            const format = (n) => new Intl.NumberFormat('en-US').format(Math.round(n));

            function render() {
                cartBody.innerHTML = '';

                cart.forEach((line, index) => {
                    const row = document.createElement('tr');

                    // Section 9b: qty and price are inline editable in the row —
                    // no modal to change a number.
                    row.innerHTML = `
                        <td>
                            <div class="fw-medium">${line.name}</div>
                            <div class="small text-secondary" dir="ltr">${line.sku}</div>
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
                                   data-role="qty" data-index="${index}">
                            <div class="small text-secondary text-end">${format(line.stock)} @json(__('in stock'))</div>
                        </td>
                        <td>
                            <input type="number" min="0" step="1" dir="ltr"
                                   class="form-control form-control-sm text-end"
                                   name="lines[${index}][unit_price]" value="${line.price}"
                                   data-role="price" data-index="${index}">
                        </td>
                        <td class="money fw-semibold">${format(line.quantity * line.price)}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-role="remove" data-index="${index}" aria-label="@json(__('Remove'))">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>`;

                    cartBody.appendChild(row);
                });

                cartEmpty.classList.toggle('d-none', cart.length > 0);
                saveButton.disabled = cart.length === 0;

                const total = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                totalEl.textContent = format(total);
                updateDue(total);
            }

            function updateDue(total) {
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
                        cost: product.next_batch_cost,
                        belowCost: product.next_batch_cost !== null && product.sale_price < product.next_batch_cost,
                    });
                }

                clearSearch();
                render();
            }

            function clearSearch() {
                searchInput.value = '';
                resultsBox.classList.add('d-none');
                resultsBox.innerHTML = '';
                highlighted = -1;
                // Focus returns to the search box after every add.
                searchInput.focus();
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
                            <span class="fw-medium">${product.name}</span>
                            <span class="small text-secondary ms-2" dir="ltr">${product.sku}</span>
                        </span>
                        <span class="small">
                            <span class="text-secondary me-2">${format(product.quantity)} @json(__('in stock'))</span>
                            <span class="fw-semibold">${format(product.sale_price)}</span>
                        </span>`;
                    item.addEventListener('click', () => addProduct(product));
                    resultsBox.appendChild(item);
                });

                resultsBox.classList.toggle('d-none', data.products.length === 0);
            }

            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                const term = searchInput.value.trim();

                if (term === '') {
                    resultsBox.classList.add('d-none');
                    return;
                }

                searchTimer = setTimeout(() => runSearch(term), 150);
            });

            searchInput.addEventListener('keydown', (event) => {
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

                render();
            });

            cartBody.addEventListener('click', (event) => {
                const button = event.target.closest('[data-role="remove"]');
                if (! button) return;

                cart.splice(Number(button.dataset.index), 1);
                render();
                searchInput.focus();
            });

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

            document.addEventListener('keydown', (event) => {
                if (event.key === 'F2' && ! saveButton.disabled) {
                    event.preventDefault();
                    document.getElementById('sale-form').requestSubmit();
                }
            });

            render();
        })();
    </script>
@endpush
