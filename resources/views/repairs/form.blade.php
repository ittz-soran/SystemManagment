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
                                       class="form-control @error('identifier') is-invalid @enderror">
                                @error('identifier')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
        })();
    </script>
@endpush
