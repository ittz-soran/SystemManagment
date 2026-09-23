@extends('layouts.app')

@section('title', $repair ? __('Edit repair') : __('Take in a repair'))
@section('subheading', $repair?->document_no ?? __('What came in, what is wrong with it, and what it will need'))

@section('back')
    <x-back-link :to="route('repairs.index')" :label="__('Repairs')" remember="repairs" permission="repairs.view" />
@endsection

@section('actions')
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    <form method="POST" data-guard-submit
          action="{{ $repair ? route('repairs.update', $repair) : route('repairs.store') }}">
        @csrf
        @if($repair) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header">{{ __('The device') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="device" class="form-label">{{ __('What is it') }}</label>
                            <input id="device" name="device" required autofocus maxlength="160"
                                   value="{{ old('device', $repair?->device) }}"
                                   class="form-control @error('device') is-invalid @enderror"
                                   placeholder="{{ __('iPhone 13, PlayStation 4, laptop, TV') }}">
                            @error('device')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label for="identifier" class="form-label">{{ __('Serial or IMEI') }}</label>
                                <input id="identifier" name="identifier" dir="ltr" maxlength="80"
                                       value="{{ old('identifier', $repair?->identifier) }}"
                                       data-history="{{ route('repairs.history') }}"
                                       class="form-control @error('identifier') is-invalid @enderror">
                                @error('identifier')<div class="invalid-feedback">{{ $message }}</div>@enderror

                                {{-- ⚠️ Filled in as the number is typed — this is
                                     the moment the shop decides what to charge,
                                     with the customer still at the counter. The
                                     job screen asks the same question on the
                                     server, so a blocked script loses the timing
                                     and not the warning. --}}
                                <div id="been-here" class="small mt-2"></div>
                            </div>

                            <div class="col-6 col-sm-3">
                                <label for="promised_for" class="form-label">{{ __('Promised') }}</label>
                                <input id="promised_for" type="date" name="promised_for" dir="ltr"
                                       value="{{ old('promised_for', $repair?->promised_for?->toDateString()) }}"
                                       class="form-control @error('promised_for') is-invalid @enderror">
                                @error('promised_for')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-6 col-sm-3">
                                <label for="estimate" class="form-label">{{ __('Estimate') }}</label>
                                <x-money-input name="estimate" :lens="$lens" :min="0"
                                               :value="$repair?->estimate"
                                               data-numpad="{{ __('Estimate') }}" />
                                @error('estimate')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="fault" class="form-label">{{ __('What is wrong') }}</label>
                            <textarea id="fault" name="fault" required rows="2" maxlength="1000"
                                      class="form-control @error('fault') is-invalid @enderror"
                                      placeholder="{{ __('Screen cracked, battery dies in an hour') }}">{{ old('fault', $repair?->fault) }}</textarea>
                            <div class="form-text">{{ __('In the customer’s own words.') }}</div>
                            @error('fault')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        {{-- ⚠️ The field that stops an argument. Marked as such on
                             the screen, not only in the code. --}}
                        <div class="mb-0">
                            <label for="condition_note" class="form-label">{{ __('How it looks now') }}</label>
                            <textarea id="condition_note" name="condition_note" rows="2" maxlength="1000"
                                      class="form-control @error('condition_note') is-invalid @enderror"
                                      placeholder="{{ __('Already scratched, no charger, small dent on the corner') }}">{{ old('condition_note', $repair?->condition_note) }}</textarea>
                            <div class="form-text">
                                {{ __('Write down every mark before it goes on the bench. This is what settles a disagreement later.') }}
                            </div>
                            @error('condition_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>{{ __('What the job needs') }}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="add-line">
                            <i class="bi bi-plus-lg me-1"></i>{{ __('Add') }}
                        </button>
                    </div>
                    <div class="card-body">
                        {{-- The shop decides these; the customer accepts them on the
                             next screen. Parts and labour are both products, which
                             is what makes the sale at the end an ordinary one. --}}
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="lines">
                                <thead>
                                <tr>
                                    <th>{{ __('Part or work') }}</th>
                                    <th style="width: 6rem">{{ __('Qty') }}</th>
                                    <th style="width: 10rem" class="money">{{ __('Price') }}</th>
                                    <th style="width: 3rem"></th>
                                </tr>
                                </thead>
                                <tbody id="lines-body"></tbody>
                            </table>
                        </div>

                        <div class="form-text mt-2">
                            {{ __('Warranty comes from each part’s own setting — a screen 5 days, a battery 30 — and is fixed when the customer accepts.') }}
                        </div>

                        {{-- ⚠️ A part the shop has not got — Soran, 2026-09-23.
                             Bought here rather than on the purchase screen,
                             because this form is half filled in with a broken
                             device described in it and leaving would throw that
                             away. It is a real purchase all the same: cash, paid,
                             against a supplier the shop set up, and it opens a
                             batch the collection consumes like any other.

                             ⚠️ No `name` attributes anywhere in here. These boxes
                             sit inside the repair's own form, and a named field
                             would be posted along with the job. --}}
                        @can('repairs.buy_part')
                            <div class="border-top mt-3 pt-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="collapse" data-bs-target="#buy-part">
                                    <i class="bi bi-cart-plus me-1"></i>{{ __('Buy a part for this job') }}
                                </button>
                                <div class="form-text">
                                    {{ __('For a part you do not stock and went out to buy. It is recorded as a cash purchase and goes on the shelf.') }}
                                </div>

                                <div class="collapse mt-3" id="buy-part">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-6">
                                            <label for="buy-name" class="form-label small">{{ __('What is it') }}</label>
                                            <input id="buy-name" maxlength="255" class="form-control form-control-sm"
                                                   list="buy-known" placeholder="{{ __('Xiaomi 13 screen') }}">
                                            {{-- Offers what the shop already has before it will make anything
                                                 new, which is what keeps one screen from becoming four products. --}}
                                            <datalist id="buy-known">
                                                @foreach($products as $known)
                                                    <option value="{{ $known->name }}"></option>
                                                @endforeach
                                            </datalist>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <label for="buy-supplier" class="form-label small">{{ __('Bought from') }}</label>
                                            <select id="buy-supplier" class="form-select form-select-sm">
                                                @foreach($suppliers as $supplier)
                                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="col-4 col-md-2">
                                            <label for="buy-qty" class="form-label small">{{ __('Qty') }}</label>
                                            <input id="buy-qty" type="number" min="1" value="1" dir="ltr"
                                                   class="form-control form-control-sm text-end">
                                        </div>

                                        <div class="col-8 col-md-3">
                                            <label for="buy-cost" class="form-label small">{{ __('What you paid') }}</label>
                                            <input id="buy-cost" dir="ltr" class="form-control form-control-sm text-end money">
                                        </div>

                                        <div class="col-6 col-md-3">
                                            <label for="buy-price" class="form-label small">{{ __('What you charge') }}</label>
                                            <input id="buy-price" dir="ltr" class="form-control form-control-sm text-end money">
                                        </div>

                                        <div class="col-6 col-md-4">
                                            <label for="buy-warranty" class="form-label small">{{ __('Warranty days') }}</label>
                                            <input id="buy-warranty" type="number" min="0" max="3650" dir="ltr"
                                                   class="form-control form-control-sm text-end"
                                                   placeholder="{{ __('None') }}">
                                        </div>

                                        <div class="col-12">
                                            <button type="button" id="buy-go" class="btn btn-sm btn-primary"
                                                    data-url="{{ route('repairs.buy-part') }}">
                                                <i class="bi bi-bag-check me-1"></i>{{ __('Buy it and add to the job') }}
                                            </button>
                                            <span id="buy-said" class="small ms-2"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">{{ __('Who it belongs to') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">{{ __('Customer') }}</label>
                            <select id="customer_id" name="customer_id" required
                                    class="form-select @error('customer_id') is-invalid @enderror">
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}"
                                        @selected(old('customer_id', $repair?->customer_id) == $customer->id)>
                                        {{ $customer->name }}@if($customer->phone) · {{ $customer->phone }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="technician_id" class="form-label">{{ __('Who will do it') }}</label>
                            <select id="technician_id" name="technician_id" class="form-select">
                                <option value="">{{ __('Not decided yet') }}</option>
                                @foreach($technicians as $technician)
                                    <option value="{{ $technician->id }}"
                                        @selected(old('technician_id', $repair?->technician_id) == $technician->id)>
                                        {{ $technician->name }}@if($technician->phone) · {{ $technician->phone }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('Printed on the customer’s ticket.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="note" class="form-label">{{ __('Note') }}</label>
                            <textarea id="note" name="note" rows="2" maxlength="1000"
                                      class="form-control">{{ old('note', $repair?->note) }}</textarea>
                        </div>

                        <div class="d-flex align-items-baseline justify-content-between border-top pt-3 mb-3">
                            <span class="text-secondary">{{ __('The job comes to') }}</span>
                            <span class="fs-4 fw-semibold money" id="running-total">—</span>
                        </div>

                        <button class="btn btn-primary w-100" data-submitting-text="{{ __('Saving…') }}">
                            <i class="bi bi-clipboard-check me-1"></i>{{ $repair ? __('Save') : __('Take it in') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    @php
        /* ⚠️ Built here rather than inside @json(...). That directive parses
           its argument, and a nested `[` in an arrow function breaks the parse
           with an error pointing at the wrong line entirely. */
        $existingLines = ($repair?->items ?? collect())->map(fn ($i) => [
            'product_id' => $i->product_id,
            'quantity' => $i->quantity,
            'unit_price' => $i->unit_price,
        ])->values();
    @endphp

    <script>
        /**
         * The job's lines.
         *
         * Deliberately plain: this is a workshop form filled in once, not the
         * till. The sale screen's cart is keyboard-first because a scanner is a
         * keyboard and speed is the whole point there; here somebody is
         * standing at a counter with a broken phone in their hand.
         */
        (() => {
            const products = @json($products);
            const body = document.getElementById('lines-body');
            const total = document.getElementById('running-total');
            const existing = @json($existingLines);

            let index = 0;

            const groupDigits = (n) => new Intl.NumberFormat().format(n);

            function recalc() {
                let sum = 0;

                body.querySelectorAll('tr').forEach((row) => {
                    const qty = Number(row.querySelector('[data-qty]').value || 0);
                    const price = Number(String(row.querySelector('input[data-price]').value || 0).replace(/,/g, ''));
                    sum += qty * price;
                });

                total.textContent = sum === 0 ? '—' : groupDigits(sum);
            }

            function addRow(line = null) {
                const i = index++;
                const row = document.createElement('tr');

                row.innerHTML = `
                    <td>
                        <select name="lines[${i}][product_id]" class="form-select form-select-sm" data-product required>
                            ${products.map((p) => `<option value="${p.id}" data-sale-price="${p.sale_price}" data-warranty="${p.warranty_days ?? ''}">${p.name}</option>`).join('')}
                        </select>
                        <div class="small text-secondary mt-1" data-warranty-note></div>
                    </td>
                    <td><input name="lines[${i}][quantity]" type="number" min="1" value="1" dir="ltr"
                               class="form-control form-control-sm text-end" data-qty required></td>
                    <td><input name="lines[${i}][unit_price]" dir="ltr"
                               class="form-control form-control-sm text-end money" data-price required></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove>
                        <i class="bi bi-x-lg"></i></button></td>`;

                body.appendChild(row);

                const select = row.querySelector('[data-product]');
                const price = row.querySelector('input[data-price]');
                const note = row.querySelector('[data-warranty-note]');

                // Picking a part fills its price and says what it is guaranteed
                // for, so the person quoting can see the promise as they make it.
                function fromProduct() {
                    const option = select.selectedOptions[0];
                    const days = option.dataset.warranty;

                    note.textContent = days === ''
                        ? @json(__('No warranty'))
                        : @json(__('Warranty :days days')).replace(':days', days);

                    return option.dataset.salePrice;
                }

                select.addEventListener('change', () => { price.value = fromProduct(); recalc(); });
                row.querySelectorAll('input[data-qty], input[data-price]').forEach((f) => f.addEventListener('input', recalc));
                row.querySelector('[data-remove]').addEventListener('click', () => { row.remove(); recalc(); });

                if (line) {
                    select.value = line.product_id;
                    row.querySelector('[data-qty]').value = line.quantity;
                    fromProduct();
                    price.value = line.unit_price;
                } else {
                    price.value = fromProduct();
                }

                recalc();
            }

            document.getElementById('add-line').addEventListener('click', () => addRow());

            existing.length ? existing.forEach(addRow) : addRow();

            /*
             * ⚠️ Has this device been here before?
             *
             * Soran, 2026-09-23: *"add warranty warning when device come
             * back"*. Asked as the identifier is typed, because that is when
             * the price is being decided and the customer is still standing
             * there. The job screen asks the same thing on the server, so this
             * is the timing rather than the warning itself.
             */
            const serial = document.getElementById('identifier');
            const beenHere = document.getElementById('been-here');

            if (serial && beenHere) {
                let asked = null;
                let timer = null;

                const look = async () => {
                    const typed = serial.value.trim();

                    // Nothing typed, or the same thing as last time: say
                    // nothing rather than ask again.
                    if (typed === '' ) {
                        beenHere.textContent = '';
                        asked = '';
                        return;
                    }

                    if (typed === asked) {
                        return;
                    }

                    asked = typed;

                    try {
                        const response = await fetch(
                            `${serial.dataset.history}?identifier=${encodeURIComponent(typed)}`,
                            { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
                        );

                        if (! response.ok) {
                            return;
                        }

                        const answer = await response.json();

                        // ⚠️ A slow answer to an older number must not land on
                        // a newer one — the same rule the search panel follows.
                        if (serial.value.trim() !== asked) {
                            return;
                        }

                        beenHere.replaceChildren();

                        if (! answer.visits.length) {
                            return;
                        }

                        const box = document.createElement('div');
                        box.className = 'alert py-2 px-3 mb-0 alert-'
                            + (answer.covered ? 'warning' : 'secondary');

                        const head = document.createElement('div');
                        head.className = 'fw-semibold';
                        head.textContent = answer.covered
                            ? @json(__('This device is still under warranty from an earlier repair'))
                            : @json(__('This device has been here before'));
                        box.appendChild(head);

                        answer.visits.forEach((visit) => {
                            const line = document.createElement('div');

                            const link = document.createElement('a');
                            link.href = visit.url;
                            link.target = '_blank';
                            link.rel = 'noopener';
                            link.className = 'fw-semibold';
                            link.textContent = visit.number;
                            line.appendChild(link);

                            visit.lines.forEach((part) => {
                                const one = document.createElement('div');
                                one.className = part.covered ? 'fw-semibold' : 'text-secondary';
                                one.textContent = part.until === null
                                    ? `${part.name} · ` + @json(__('no warranty'))
                                    : `${part.name} · ` + (part.covered
                                        ? @json(__('covered until :date')).replace(':date', part.until)
                                        : @json(__('ran out :date')).replace(':date', part.until));
                                line.appendChild(one);
                            });

                            box.appendChild(line);
                        });

                        beenHere.appendChild(box);
                    } catch (e) {
                        // A lookup that cannot be made is a warning the shop
                        // does not get, not a form it cannot use.
                    }
                };

                // Waits for the typing to stop: an IMEI is fifteen digits and
                // nobody needs fifteen questions asked about it.
                serial.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(look, 400);
                });

                serial.addEventListener('change', look);

                // An edit form arrives with the number already in the box.
                if (serial.value.trim() !== '') {
                    look();
                }
            }

            /*
             * ⚠️ Buying a part the shop has not got, without leaving the form.
             *
             * The whole reason this is here rather than on the purchase screen
             * is that this page is half filled in with a broken device
             * described in it. So the purchase goes over `fetch`, and what
             * comes back is added to the list in place — nothing typed is lost,
             * and the part is on the shelf before the customer is quoted.
             */
            const buy = document.getElementById('buy-go');

            if (buy) {
                const said = document.getElementById('buy-said');
                const field = (id) => document.getElementById(id);
                const plain = (id) => String(field(id).value || '').replace(/,/g, '').trim();

                const say = (text, ok) => {
                    said.textContent = text;
                    said.className = 'small ms-2 ' + (ok ? 'text-success' : 'text-danger');
                };

                buy.addEventListener('click', async () => {
                    if (! field('buy-name').value.trim()) {
                        say(@json(__('Say what the part is.')), false);
                        return;
                    }

                    // ⚠️ Disabled for the round trip. A second click is a second
                    // purchase, and the shop would have bought two screens.
                    buy.disabled = true;
                    say(@json(__('Buying…')), true);

                    try {
                        const response = await fetch(buy.dataset.url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                name: field('buy-name').value.trim(),
                                supplier_id: field('buy-supplier').value,
                                quantity: Number(field('buy-qty').value || 1),
                                unit_cost: plain('buy-cost'),
                                sale_price: plain('buy-price'),
                                warranty_days: field('buy-warranty').value === ''
                                    ? null
                                    : Number(field('buy-warranty').value),
                            }),
                        });

                        const answer = await response.json();

                        if (! response.ok) {
                            // Laravel returns its field errors under `errors`;
                            // the service returns one sentence under `message`.
                            const first = answer.errors
                                ? Object.values(answer.errors)[0][0]
                                : answer.message;

                            say(first || @json(__('That did not work.')), false);
                            return;
                        }

                        /*
                         * ⚠️ Added to the shared list as well as to this row.
                         * Every row is built from `products`, so a part missing
                         * from it would vanish out of the next row's dropdown —
                         * a part the shop has just paid for.
                         */
                        products.push(answer.product);

                        addRow({
                            product_id: answer.product.id,
                            quantity: Number(field('buy-qty').value || 1),
                            unit_price: answer.product.sale_price,
                        });

                        say(answer.message, true);

                        field('buy-name').value = '';
                        field('buy-cost').value = '';
                        field('buy-price').value = '';
                        field('buy-warranty').value = '';
                        field('buy-qty').value = 1;
                    } catch (e) {
                        say(@json(__('That did not work.')), false);
                    } finally {
                        buy.disabled = false;
                    }
                });
            }
        })();
    </script>
@endpush
