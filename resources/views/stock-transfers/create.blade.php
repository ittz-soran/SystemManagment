@extends('layouts.app')

@section('title', __('Move stock'))

@section('content')
    {{--
        **Soran, 2026-09-15:** any room to any room.

        **2026-09-18:** *"change move mechanism to same as sale/purchase to
        search products, show cart"*. It listed the whole room with a box on
        every line, which meant a room of 306 products was 306 boxes to scroll
        past to reach the one crate being carried — and it posted all of them.
        It is a cart now, like the two screens everybody already knows.

        ⚠️ The search still offers only what the FROM room actually holds. A
        search across the catalogue would offer lines the engine is going to
        refuse, one at a time.
    --}}
    <form method="POST" action="{{ route('stock-transfers.store') }}" id="transfer-form" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label for="from_room_id" class="form-label">{{ __('From') }}</label>
                                <select id="from_room_id" name="from_room_id" class="form-select" required
                                        data-stock-url="{{ route('stock-transfers.stock', ['stockRoom' => '__ROOM__']) }}">
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}" @selected(old('from_room_id', $fromId) == $room->id)>
                                            {{ $room->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-2 d-none d-md-flex align-items-end justify-content-center pb-2">
                                {{-- Mirrors in RTL with the rest of the page, which
                                     is why it is an icon rather than an arrow drawn
                                     into the label. --}}
                                <i class="bi bi-arrow-right fs-4 text-secondary" aria-hidden="true"></i>
                            </div>

                            <div class="col-md-5">
                                <label for="to_room_id" class="form-label">{{ __('To') }}</label>
                                {{-- ⚠️ Never the same room this is coming FROM.
                                     Both dropdowns listing every room in the
                                     same order meant "To" opened on the room
                                     already chosen in "From", so the first save
                                     anybody tried was always refused. --}}
                                @php($toDefault = old('to_room_id') ?: $rooms->first(fn ($r) => $r->is_active && $r->id !== (int) $fromId)?->id)
                                <select id="to_room_id" name="to_room_id" class="form-select" required>
                                    @foreach($rooms as $room)
                                        @continue(! $room->is_active)
                                        <option value="{{ $room->id }}" @selected($toDefault == $room->id)>
                                            {{ $room->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">{{ __('Closed rooms are not offered.') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="transferred_at" class="form-label">{{ __('Date') }}</label>
                                <input id="transferred_at" type="date" name="transferred_at" class="form-control"
                                       value="{{ old('transferred_at', today()->toDateString()) }}" required>
                            </div>

                            <div class="col-md-6">
                                <label for="note" class="form-label">{{ __('Note') }}</label>
                                <input id="note" name="note" class="form-control" maxlength="255" value="{{ old('note') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ __('What is moving') }}</div>

                    <div class="card-body pb-0">
                        <label for="product-search" class="form-label">{{ __('Find a product') }}</label>
                        <div class="position-relative">
                            <input id="product-search" class="form-control" autocomplete="off"
                                   placeholder="{{ __('Name, SKU or barcode') }}">
                            {{-- Absolute, so the rows below do not jump down the
                                 page every time somebody types a letter. --}}
                            <div id="search-results" class="list-group position-absolute w-100 shadow d-none"
                                 style="z-index: 5"></div>
                        </div>
                        <div class="form-text">{{ __('Only what this room holds.') }}</div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="transfer-lines">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="text-end" style="width: 10rem">{{ __('In this room') }}</th>
                                <th class="text-end" style="width: 11rem">{{ __('Move') }}</th>
                                <th style="width: 3rem"></th>
                            </tr>
                            </thead>
                            <tbody id="transfer-rows">
                                {{-- The cart: only what somebody chose. --}}
                            </tbody>
                        </table>
                    </div>

                    <div id="transfer-empty" class="p-4 text-center text-secondary small">
                        {{ __('Nothing in the cart yet. Find a product above.') }}
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="text-secondary small">{{ __('Units moving') }}</div>
                        <div class="running-total" id="transfer-total">0</div>
                        <div class="small text-secondary" id="transfer-lines-count"></div>
                    </div>
                </div>

                {{-- ⚠️ Not sticky. A bottom-sticky block is pinned to the bottom
                     of the window and paints over whatever the last field is —
                     see sales/create.blade.php for the measurement. --}}
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg" id="transfer-save" disabled>
                        {{ __('Move stock') }}
                    </button>
                    <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    /**
     * The transfer cart — Soran, 2026-09-18.
     *
     * *"change move mechanism to same as sale/purchase to search products,
     * show cart"*.
     *
     * ⚠️ Rows are built with createElement and textContent, never innerHTML: a
     * product name is typed by a shopkeeper and drawn on somebody else's screen.
     */
    (function () {
        const form = document.getElementById('transfer-form');
        const from = document.getElementById('from_room_id');
        const search = document.getElementById('product-search');
        const results = document.getElementById('search-results');
        const rows = document.getElementById('transfer-rows');
        const empty = document.getElementById('transfer-empty');
        const total = document.getElementById('transfer-total');
        const counted = document.getElementById('transfer-lines-count');
        const save = document.getElementById('transfer-save');

        const words = {
            lines: @json(__(':count products')),
            remove: @json(__('Remove')),
        };

        /** The cart: what somebody chose, in the order they chose it. */
        let cart = [];
        let highlighted = -1;
        let timer = null;

        const number = new Intl.NumberFormat('en-US');

        function sum() {
            const units = cart.reduce((n, line) => n + (Number(line.quantity) || 0), 0);

            total.textContent = number.format(units);
            counted.textContent = words.lines.replace(':count', String(cart.length));
            empty.classList.toggle('d-none', cart.length > 0);

            // Nothing to move is not a transfer. The server says the same, in a
            // sentence, for anybody who gets past this.
            save.disabled = units === 0;
        }

        function draw() {
            rows.replaceChildren();

            cart.forEach((line, index) => {
                const tr = document.createElement('tr');

                const name = document.createElement('td');
                const strong = document.createElement('div');
                strong.textContent = line.name;
                const sku = document.createElement('div');
                sku.className = 'small text-secondary app-code';
                sku.textContent = line.sku ?? '';
                name.append(strong, sku);

                const has = document.createElement('td');
                has.className = 'text-end';
                has.textContent = number.format(line.units) + (line.unit ? ' ' + line.unit : '');

                const cell = document.createElement('td');
                const id = document.createElement('input');
                id.type = 'hidden';
                id.name = `lines[${index}][product_id]`;
                id.value = line.id;

                const box = document.createElement('input');
                box.type = 'number';
                box.className = 'form-control form-control-sm text-end';
                box.dir = 'ltr';
                box.min = '1';

                // ⚠️ What the ROOM holds, not what the shop holds. Moving more
                // than is on that shelf is the one mistake this screen can
                // stop before the engine has to.
                box.max = String(line.units);
                box.value = String(line.quantity);
                box.name = `lines[${index}][quantity]`;
                box.setAttribute('data-english-digits', '');
                box.addEventListener('input', () => {
                    line.quantity = Math.max(0, Math.min(Number(box.value) || 0, line.units));
                    sum();
                });

                cell.append(id, box);

                const remove = document.createElement('td');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-danger';
                button.title = words.remove;
                button.setAttribute('aria-label', words.remove);
                button.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
                button.addEventListener('click', () => {
                    cart.splice(index, 1);
                    draw();
                });
                remove.append(button);

                tr.append(name, has, cell, remove);
                rows.append(tr);
            });

            sum();
        }

        function hideResults() {
            results.classList.add('d-none');
            results.replaceChildren();
            highlighted = -1;
        }

        function add(product) {
            const already = cart.find((line) => line.id === product.id);

            // The same product twice is somebody remembering another crate, not
            // a mistake — the purchase cart reads it the same way.
            if (already) {
                already.quantity = Math.min(already.quantity + 1, already.units);
            } else {
                cart.push({ ...product, units: Number(product.units), quantity: 1 });
            }

            search.value = '';
            hideResults();
            draw();
        }

        async function runSearch(term) {
            const url = from.dataset.stockUrl.replace('__ROOM__', from.value);

            let data;

            try {
                const response = await fetch(`${url}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                });

                if (! response.ok) return;

                data = await response.json();
            } catch {
                return;
            }

            // A scan is a whole code and means one product, so it goes in.
            if (data.exact && data.products.length === 1) {
                add(data.products[0]);

                return;
            }

            results.replaceChildren();
            highlighted = -1;

            data.products.forEach((product) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action d-flex justify-content-between';

                const left = document.createElement('span');
                const label = document.createElement('span');
                label.className = 'fw-medium';
                label.textContent = product.name;
                const code = document.createElement('span');
                code.className = 'small text-secondary ms-2';
                code.dir = 'ltr';
                code.textContent = product.sku ?? '';
                left.append(label, code);

                const right = document.createElement('span');
                right.className = 'small text-secondary';
                right.textContent = number.format(product.units) + (product.unit ? ' ' + product.unit : '');

                item.append(left, right);
                item.addEventListener('click', () => add(product));
                results.append(item);
            });

            results.classList.toggle('d-none', data.products.length === 0);
        }

        search.addEventListener('input', () => {
            const term = search.value.trim();

            window.clearTimeout(timer);

            if (term.length < 2) {
                hideResults();

                return;
            }

            timer = window.setTimeout(() => runSearch(term), 150);
        });

        search.addEventListener('keydown', (event) => {
            const items = [...results.querySelectorAll('.list-group-item')];

            if (event.key === 'Escape') {
                hideResults();

                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();

                if (items[highlighted]) items[highlighted].click();

                return;
            }

            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

            event.preventDefault();

            if (items.length === 0) return;

            highlighted = event.key === 'ArrowDown'
                ? Math.min(highlighted + 1, items.length - 1)
                : Math.max(highlighted - 1, 0);

            items.forEach((item, i) => item.classList.toggle('active', i === highlighted));
            items[highlighted].scrollIntoView({ block: 'nearest' });
        });

        document.addEventListener('click', (event) => {
            if (! results.contains(event.target) && event.target !== search) hideResults();
        });

        /*
         * ⚠️ Changing the room empties the cart, and it has to.
         *
         * Every line carries what THAT room held. Keeping them would leave
         * quantities checked against a shelf the transfer is no longer leaving
         * from — a cart that looks right and is refused line by line.
         */
        from.addEventListener('change', () => {
            cart = [];
            search.value = '';
            hideResults();
            draw();
        });

        form.addEventListener('submit', () => {
            // A row left at zero is somebody changing their mind, not a line.
            cart = cart.filter((line) => Number(line.quantity) > 0);
            draw();
        });

        draw();
    })();
</script>
@endpush
