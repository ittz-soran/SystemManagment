@extends('layouts.app')

@section('title', __('Return to supplier'))
@section('subheading')
    {{ __('Against purchase :document', ['document' => $purchase->document_no]) }} · {{ $purchase->supplier->name }}
@endsection

@section('content')
    @php
        // Section 7: purchase returns are limited by the batch — you can't send
        // back goods you no longer hold. So the cap is the smaller of what is
        // still unreturned on the line and what remains in its batch.
        $caps = $purchase->items->mapWithKeys(fn ($item) => [
            $item->id => min($item->returnableQuantity(), $batchStock[$item->id] ?? 0),
        ]);
    @endphp

    @if($caps->sum() === 0)
        <div class="card">
            <x-empty-state icon="check-circle"
                           :message="__('Nothing on this purchase can be returned — it has either been returned already or the stock has since been sold.')"
                           :action="route('purchases.show', $purchase)"
                           :action-label="__('Back to the purchase')" />
        </div>
    @else
        <form action="{{ route('purchase-returns.store', $purchase) }}" method="POST" id="return-form" data-guard-submit>
            @csrf

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>{{ __('What is going back?') }}</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="return-all">
                                {{ __('Return all') }}
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="return-table">
                                <thead>
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th class="money">{{ __('Bought') }}</th>
                                    <th class="money">{{ __('Returnable') }}</th>
                                    <th class="money" style="width: 6.5rem">{{ __('Return now') }}</th>
                                    <th class="money" style="width: 10rem">{{ __('Credit each') }}</th>
                                    <th class="money">{{ __('Total credit') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase->items as $index => $item)
                                    @php
                                        $cap = $caps[$item->id];
                                        $share = $discountShares[$item->id];
                                    @endphp

                                    <tr class="{{ $cap === 0 ? 'opacity-50' : '' }}">
                                        <td>
                                            <div class="fw-medium">{{ $item->product->name }}</div>
                                            <div class="small text-secondary" dir="ltr">{{ $item->product->sku }}</div>
                                            @if($cap < $item->returnableQuantity())
                                                <div class="small text-warning">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                    {{ __('Only :count still in its batch — the rest has been sold.', ['count' => $cap]) }}
                                                </div>
                                            @endif
                                            <input type="hidden" name="lines[{{ $index }}][purchase_item_id]" value="{{ $item->id }}">
                                            <input type="hidden" name="lines[{{ $index }}][discount_share]"
                                                   value="0" data-role="share-value">
                                        </td>
                                        <td class="money">{{ number_format($item->quantity) }}</td>
                                        <td class="money fw-semibold">{{ number_format($cap) }}</td>
                                        <td>
                                            <input type="number" min="0" max="{{ $cap }}" step="1" dir="ltr"
                                                   class="form-control form-control-sm text-end"
                                                   name="lines[{{ $index }}][quantity]" value="0"
                                                   data-role="qty" data-max="{{ $cap }}"
                                                   @disabled($cap === 0)>
                                        </td>
                                        <td>
                                            {{-- Section 7: pre-filled at the FULL typed
                                                 unit price. Editable, because a
                                                 negotiated credit is normal. --}}
                                            <input type="number" min="0" step="1" dir="ltr"
                                                   class="form-control form-control-sm text-end"
                                                   name="lines[{{ $index }}][unit_price]"
                                                   value="{{ $item->unit_price }}"
                                                   data-role="price"
                                                   data-full="{{ $item->unit_price }}"
                                                   data-share="{{ $share }}"
                                                   @disabled($cap === 0)>

                                            @if($share > 0)
                                                {{-- The calculated share, shown beside
                                                     the credit as a hint and a one-click
                                                     way to apply it. Not the default. --}}
                                                <div class="small text-secondary text-end mt-1">
                                                    <button type="button" class="btn btn-link btn-sm p-0 small"
                                                            data-role="apply-share">
                                                        {{ __('Apply share: :amount', ['amount' => money($item->unit_price - $share, false)]) }}
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="money" data-role="credit">0</td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="5" class="text-end">{{ __('Total credit') }}</td>
                                    <td class="money" id="credit-total">0</td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>

                        @if($purchase->discount_amount > 0)
                            <div class="card-footer small text-secondary">
                                {{ __('This purchase had a :amount discount. By default the supplier is credited the full typed price; applying the share credits proportionally instead.', [
                                    'amount' => money($purchase->discount_amount),
                                ]) }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="return_date" class="form-label">{{ __('Date') }}</label>
                                <input id="return_date" type="date" name="return_date" class="form-control"
                                       value="{{ old('return_date', today()->toDateString()) }}" required>
                            </div>

                            <div class="mb-3">
                                <label for="reason" class="form-label">{{ __('Reason') }}</label>
                                <input id="reason" name="reason" class="form-control" value="{{ old('reason') }}"
                                       placeholder="{{ __('Faulty, wrong item, overstocked…') }}">
                            </div>

                            <div>
                                <label for="payment_method" class="form-label">{{ __('If cash comes back') }}</label>
                                <select id="payment_method" name="payment_method" class="form-select">
                                    <option value="cash">{{ __('Cash') }}</option>
                                    <option value="bank">{{ __('Bank') }}</option>
                                    <option value="transfer">{{ __('Transfer') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="text-secondary small">{{ __('Total credit') }}</div>
                            <div class="running-total mb-3" id="credit-headline">0</div>

                            {{-- Section 4: if the return exceeds what you still owe,
                                 the balance clears to zero and the remainder is cash
                                 received back from the supplier. --}}
                            <div class="small">
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">{{ __('You currently owe') }}</span>
                                    <span class="money">{{ money($purchase->supplier->balance, false) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">{{ __('Applied to what you owe') }}</span>
                                    <span class="money" id="applied-to-balance">0</span>
                                </div>
                                <div class="d-flex justify-content-between fw-semibold">
                                    <span>{{ __('Cash back from supplier') }}</span>
                                    <span class="money" id="cash-back">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 position-sticky" style="bottom: 1rem">
                        <button type="submit" class="btn btn-primary btn-lg" id="save-return" disabled
                                data-submitting-text="{{ __('Saving…') }}">
                            {{ __('Save return') }}
                        </button>
                        <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-outline-secondary">
                            {{ __('Cancel') }}
                        </a>
                    </div>
                </div>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const table = document.getElementById('return-table');
            if (! table) return;

            const totalEl = document.getElementById('credit-total');
            const headlineEl = document.getElementById('credit-headline');
            const appliedEl = document.getElementById('applied-to-balance');
            const cashEl = document.getElementById('cash-back');
            const saveButton = document.getElementById('save-return');

            const owed = {{ (int) $purchase->supplier->balance }};
            const format = (n) => new Intl.NumberFormat('en-US').format(Math.round(n));

            function recalculate() {
                let total = 0;

                table.querySelectorAll('tbody tr').forEach((row) => {
                    const qtyInput = row.querySelector('[data-role="qty"]');
                    const priceInput = row.querySelector('[data-role="price"]');
                    if (! qtyInput || ! priceInput) return;

                    const max = Number(qtyInput.dataset.max);
                    let qty = Number(qtyInput.value || 0);

                    if (qty < 0) qty = 0;
                    if (qty > max) { qty = max; qtyInput.value = max; }

                    const price = Math.max(0, Number(priceInput.value || 0));
                    const credit = qty * price;

                    row.querySelector('[data-role="credit"]').textContent = format(credit);

                    // discount_share is stored as a whole-line figure, so the
                    // per-unit share is multiplied by the quantity returned.
                    const shareInput = row.querySelector('[data-role="share-value"]');
                    const perUnitShare = Number(priceInput.dataset.share || 0);
                    const applied = priceInput.dataset.shareApplied === '1';
                    shareInput.value = applied ? perUnitShare * qty : 0;

                    // The service computes credit as (qty x unit_price) - share,
                    // so when the share is applied the stored price stays full.
                    total += credit - Number(shareInput.value);
                });

                totalEl.textContent = format(total);
                headlineEl.textContent = format(total);

                const applied = Math.min(owed, total);
                appliedEl.textContent = format(applied);
                cashEl.textContent = format(total - applied);

                saveButton.disabled = total <= 0;
            }

            table.addEventListener('input', (event) => {
                if (['qty', 'price'].includes(event.target.dataset.role)) recalculate();
            });

            table.addEventListener('click', (event) => {
                const button = event.target.closest('[data-role="apply-share"]');
                if (! button) return;

                const priceInput = button.closest('tr').querySelector('[data-role="price"]');
                const applying = priceInput.dataset.shareApplied !== '1';

                priceInput.dataset.shareApplied = applying ? '1' : '0';
                button.classList.toggle('fw-bold', applying);
                recalculate();
            });

            document.getElementById('return-all').addEventListener('click', () => {
                table.querySelectorAll('[data-role="qty"]').forEach((input) => {
                    input.value = input.dataset.max;
                });
                recalculate();
            });

            recalculate();
        })();
    </script>
@endpush
