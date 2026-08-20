@extends('layouts.app')

@section('title', __('New purchase'))

@section('content')
    <form action="{{ route('purchases.store') }}" method="POST" id="purchase-form" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <label for="product-search" class="form-label small">
                            {{ __('Scan a barcode, or search by name or SKU') }}
                        </label>
                        <input id="product-search" type="text" class="form-control form-control-lg"
                               autocomplete="off" autofocus placeholder="{{ __('Scan or type…') }}">
                        <div id="search-results" class="list-group mt-2 d-none"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money" style="width: 6.5rem">{{ __('Quantity') }}</th>
                                <th style="width: 6rem">{{ __('Currency') }}</th>
                                <th class="money" style="width: 10rem">{{ __('Unit price') }}</th>
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
                            <label for="supplier_id" class="form-label">{{ __('Supplier') }}</label>
                            <select id="supplier_id" name="supplier_id" class="form-select" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>
                                        {{ $supplier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="supplier_invoice_no" class="form-label">{{ __("Supplier's invoice number") }}</label>
                            <input id="supplier_invoice_no" name="supplier_invoice_no" class="form-control" dir="ltr"
                                   value="{{ old('supplier_invoice_no') }}">
                            <div class="form-text">{{ __('Their number on their paperwork. Useful when reconciling.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="purchase_date" class="form-label">{{ __('Date') }}</label>
                            <input id="purchase_date" type="date" name="purchase_date" class="form-control"
                                   value="{{ old('purchase_date', today()->toDateString()) }}" required>
                        </div>

                        {{-- Section 6b: the USD entry helper. Only IQD is ever
                             stored — this is a calculator on the entry form. --}}
                        <div>
                            <label for="exchange_rate" class="form-label">{{ __('USD rate') }}</label>
                            <div class="input-group">
                                <span class="input-group-text">$1 =</span>
                                <input id="exchange_rate" type="number" step="1" min="1" name="exchange_rate"
                                       class="form-control text-end" dir="ltr" value="{{ old('exchange_rate', $usdRate) }}">
                                <span class="input-group-text">{{ __('IQD') }}</span>
                            </div>
                            <div class="form-text" id="rate-warning"></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">{{ __('Subtotal') }}</span>
                            <span class="money" id="subtotal">0</span>
                        </div>

                        <div class="mb-3">
                            <label for="discount_amount" class="form-label small">{{ __('Invoice discount') }}</label>
                            <input id="discount_amount" type="number" step="1" name="discount_amount"
                                   class="form-control form-control-sm text-end" dir="ltr" value="0">
                            {{-- Section 6: signed, because a supplier may round UP.
                                 It never touches item prices or batch costs. --}}
                            <div class="form-text">
                                {{ __('Negative if the supplier rounded up. This never changes any batch cost.') }}
                            </div>
                        </div>

                        <div class="text-secondary small">{{ __('Grand total') }}</div>
                        <div class="running-total mb-3" id="grand-total">0</div>

                        <div class="mb-3">
                            <label for="amount_paid" class="form-label">{{ __('Paid now') }}</label>
                            <div class="input-group">
                                <input id="amount_paid" type="number" step="1" min="0" name="amount_paid"
                                       class="form-control text-end" dir="ltr" value="0">
                                <button type="button" class="btn btn-outline-secondary" id="pay-full">{{ __('Full') }}</button>
                            </div>
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
                    </div>
                </div>

                <div class="d-grid gap-2 position-sticky" style="bottom: 1rem">
                    <button type="submit" class="btn btn-primary btn-lg" id="save-purchase" disabled
                            data-submitting-text="{{ __('Saving…') }}">
                        {{ __('Save purchase') }} <kbd class="ms-1">F2</kbd>
                    </button>
                    <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        (() => {
            const searchInput = document.getElementById('product-search');
            const resultsBox = document.getElementById('search-results');
            const cartBody = document.getElementById('cart-body');
            const cartEmpty = document.getElementById('cart-empty');
            const subtotalEl = document.getElementById('subtotal');
            const grandTotalEl = document.getElementById('grand-total');
            const discountInput = document.getElementById('discount_amount');
            const paidInput = document.getElementById('amount_paid');
            const dueNote = document.getElementById('due-note');
            const rateInput = document.getElementById('exchange_rate');
            const rateWarning = document.getElementById('rate-warning');
            const saveButton = document.getElementById('save-purchase');

            const defaultRate = {{ (int) $usdRate }};
            const cart = [];
            let highlighted = -1;
            let searchTimer = null;

            const format = (n) => new Intl.NumberFormat('en-US').format(Math.round(n));

            function render() {
                cartBody.innerHTML = '';

                cart.forEach((line, index) => {
                    const row = document.createElement('tr');

                    row.innerHTML = `
                        <td>
                            <div class="fw-medium">${line.name}</div>
                            <div class="small text-secondary" dir="ltr">${line.sku}</div>
                            <input type="hidden" name="lines[${index}][product_id]" value="${line.id}">
                            <input type="hidden" name="lines[${index}][entered_currency]" value="${line.currency}">
                            <input type="hidden" name="lines[${index}][entered_amount]" value="${line.currency === 'USD' ? Math.round(line.enteredAmount * 100) : ''}">
                        </td>
                        <td>
                            <input type="number" min="1" step="1" dir="ltr"
                                   class="form-control form-control-sm text-end"
                                   name="lines[${index}][quantity]" value="${line.quantity}"
                                   data-role="qty" data-index="${index}">
                        </td>
                        <td>
                            <select class="form-select form-select-sm" data-role="currency" data-index="${index}">
                                <option value="IQD" ${line.currency === 'IQD' ? 'selected' : ''}>IQD</option>
                                <option value="USD" ${line.currency === 'USD' ? 'selected' : ''}>USD</option>
                            </select>
                        </td>
                        <td>
                            ${line.currency === 'USD'
                                ? `<input type="number" min="0" step="0.01" dir="ltr"
                                          class="form-control form-control-sm text-end"
                                          value="${line.enteredAmount}" data-role="usd" data-index="${index}">
                                   <div class="small text-secondary text-end">= ${format(line.price)} @json(__('IQD'))</div>`
                                : `<input type="number" min="0" step="1" dir="ltr"
                                          class="form-control form-control-sm text-end"
                                          value="${line.price}" data-role="price" data-index="${index}">`}
                            <input type="hidden" name="lines[${index}][unit_price]" value="${line.price}">
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
                recalculate();
            }

            function recalculate() {
                const subtotal = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                const discount = Number(discountInput.value || 0);
                const grandTotal = subtotal - discount;

                subtotalEl.textContent = format(subtotal);
                grandTotalEl.textContent = format(grandTotal);

                const due = grandTotal - Number(paidInput.value || 0);
                dueNote.textContent = due > 0
                    ? @json(__('Remaining on account:')) + ' ' + format(due)
                    : @json(__('Paid in full'));
            }

            // Section 6b: round the UNIT price to whole dinars first, then
            // multiply by quantity. Never convert the line total and divide.
            function usdToIqd(amount) {
                return Math.round(Number(amount || 0) * Number(rateInput.value || 0));
            }

            function addProduct(product) {
                const existing = cart.find((l) => l.id === product.id && l.currency === 'IQD');

                if (existing) {
                    existing.quantity += 1;
                } else {
                    cart.push({
                        id: product.id,
                        name: product.name,
                        sku: product.sku,
                        quantity: 1,
                        currency: 'IQD',
                        enteredAmount: 0,
                        // Section 9: the purchase cart defaults to the last
                        // purchase price. A first-time purchase has none, so it
                        // starts at 0 and must be typed.
                        price: product.purchase_price,
                    });
                }

                searchInput.value = '';
                resultsBox.classList.add('d-none');
                highlighted = -1;
                searchInput.focus();
                render();
            }

            async function runSearch(term) {
                const response = await fetch(`{{ route('products.search') }}?q=${encodeURIComponent(term)}`, {
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
                            <span class="fw-medium">${product.name}</span>
                            <span class="small text-secondary ms-2" dir="ltr">${product.sku}</span>
                        </span>
                        <span class="small text-secondary">${format(product.purchase_price)}</span>`;
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

            cartBody.addEventListener('input', (event) => {
                const index = Number(event.target.dataset.index);
                const line = cart[index];
                if (! line) return;

                const role = event.target.dataset.role;

                if (role === 'qty') {
                    line.quantity = Math.max(1, Number(event.target.value || 1));
                    recalculate();
                    cartBody.querySelectorAll('tr')[index].querySelector('.money').textContent =
                        format(line.quantity * line.price);
                } else if (role === 'price') {
                    line.price = Math.max(0, Number(event.target.value || 0));
                    render();
                } else if (role === 'usd') {
                    line.enteredAmount = Number(event.target.value || 0);
                    line.price = usdToIqd(line.enteredAmount);
                    render();
                }
            });

            cartBody.addEventListener('change', (event) => {
                if (event.target.dataset.role !== 'currency') return;

                const line = cart[Number(event.target.dataset.index)];
                if (! line) return;

                line.currency = event.target.value;

                if (line.currency === 'USD') {
                    line.enteredAmount = 0;
                    line.price = 0;
                }

                render();
            });

            cartBody.addEventListener('click', (event) => {
                const button = event.target.closest('[data-role="remove"]');
                if (! button) return;

                cart.splice(Number(button.dataset.index), 1);
                render();
            });

            rateInput.addEventListener('input', () => {
                // Section 6b: warn if the entered rate differs from the default
                // by more than ~10%, which usually means a typo.
                const rate = Number(rateInput.value || 0);

                rateWarning.textContent = defaultRate > 0 && rate > 0
                    && Math.abs(rate - defaultRate) / defaultRate > 0.1
                    ? @json(__('That is more than 10% away from the saved rate. Check for a typo.'))
                    : '';
                rateWarning.className = rateWarning.textContent ? 'form-text text-warning' : 'form-text';

                cart.forEach((line) => {
                    if (line.currency === 'USD') line.price = usdToIqd(line.enteredAmount);
                });

                render();
            });

            discountInput.addEventListener('input', recalculate);
            paidInput.addEventListener('input', recalculate);

            document.getElementById('pay-full').addEventListener('click', () => {
                const subtotal = cart.reduce((sum, l) => sum + l.quantity * l.price, 0);
                paidInput.value = Math.max(0, subtotal - Number(discountInput.value || 0));
                recalculate();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'F2' && ! saveButton.disabled) {
                    event.preventDefault();
                    document.getElementById('purchase-form').requestSubmit();
                }
            });

            render();
        })();
    </script>
@endpush
