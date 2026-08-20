@extends('layouts.app')

@section('title', __('Return items'))
@section('subheading')
    {{ __('Against invoice :document', ['document' => $sale->document_no]) }} · {{ $sale->customer->name }}
@endsection

@section('content')
    @php
        $returnable = $sale->items->sum(fn ($item) => $item->returnableQuantity());
    @endphp

    @if($returnable === 0)
        <div class="card">
            <x-empty-state icon="check-circle"
                           :message="__('Everything on this invoice has already been returned.')"
                           :action="route('sales.show', $sale)"
                           :action-label="__('Back to the invoice')" />
        </div>
    @else
        <form action="{{ route('sale-returns.store', $sale) }}" method="POST" id="return-form" data-guard-submit>
            @csrf

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>{{ __('What is coming back?') }}</span>
                            {{-- Section 7: a "return all" button fills every box
                                 with the returnable quantity. --}}
                            <button type="button" class="btn btn-sm btn-outline-primary" id="return-all">
                                {{ __('Return all') }}
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="return-table">
                                <thead>
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th class="money">{{ __('Sold') }}</th>
                                    <th class="money">{{ __('Already returned') }}</th>
                                    <th class="money">{{ __('Returnable') }}</th>
                                    <th class="money" style="width: 7rem">{{ __('Return now') }}</th>
                                    <th class="money">{{ __('Refund') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($sale->items as $index => $item)
                                    @php $canReturn = $item->returnableQuantity(); @endphp

                                    <tr class="{{ $canReturn === 0 ? 'opacity-50' : '' }}">
                                        <td>
                                            <div class="fw-medium">{{ $item->product->name }}</div>
                                            <div class="small text-secondary" dir="ltr">{{ $item->product->sku }}</div>
                                            <input type="hidden" name="lines[{{ $index }}][sale_item_id]" value="{{ $item->id }}">
                                        </td>
                                        <td class="money">{{ number_format($item->quantity) }}</td>
                                        <td class="money text-secondary">{{ number_format($item->quantity_returned) }}</td>
                                        <td class="money fw-semibold">{{ number_format($canReturn) }}</td>
                                        <td>
                                            <input type="number" min="0" max="{{ $canReturn }}" step="1" dir="ltr"
                                                   class="form-control form-control-sm text-end"
                                                   name="lines[{{ $index }}][quantity]" value="0"
                                                   data-role="qty"
                                                   data-price="{{ $item->unit_price }}"
                                                   data-max="{{ $canReturn }}"
                                                   @disabled($canReturn === 0)>
                                        </td>
                                        {{-- Section 7: the refund uses THIS line's
                                             unit price — the same product on two
                                             lines refunds differently. --}}
                                        <td class="money" data-role="refund">0</td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="5" class="text-end">{{ __('Total refund') }}</td>
                                    <td class="money" id="refund-total">0</td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>
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
                                <input id="reason" name="reason" class="form-control"
                                       value="{{ old('reason') }}"
                                       placeholder="{{ __('Faulty, wrong item, changed their mind…') }}">
                            </div>

                            <div>
                                <label for="payment_method" class="form-label">{{ __('Refund method') }}</label>
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
                            <div class="text-secondary small">{{ __('Total refund') }}</div>
                            <div class="running-total mb-3" id="refund-headline">0</div>

                            {{-- Section 7: a refund first clears what the customer
                                 owes; anything left over is paid back in cash. The
                                 balance never goes below zero. --}}
                            <div class="small">
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">{{ __('They currently owe') }}</span>
                                    <span class="money">{{ money($sale->customer->balance, false) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-secondary">{{ __('Applied to their balance') }}</span>
                                    <span class="money" id="applied-to-balance">0</span>
                                </div>
                                <div class="d-flex justify-content-between fw-semibold">
                                    <span>{{ __('Paid back in cash') }}</span>
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
                        <a href="{{ route('sales.show', $sale) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
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

            const totalEl = document.getElementById('refund-total');
            const headlineEl = document.getElementById('refund-headline');
            const appliedEl = document.getElementById('applied-to-balance');
            const cashEl = document.getElementById('cash-back');
            const saveButton = document.getElementById('save-return');

            const owed = {{ (int) $sale->customer->balance }};
            const format = (n) => new Intl.NumberFormat('en-US').format(Math.round(n));

            function recalculate() {
                let total = 0;

                table.querySelectorAll('[data-role="qty"]').forEach((input) => {
                    const max = Number(input.dataset.max);
                    let qty = Number(input.value || 0);

                    if (qty < 0) qty = 0;
                    if (qty > max) { qty = max; input.value = max; }

                    const refund = qty * Number(input.dataset.price);
                    input.closest('tr').querySelector('[data-role="refund"]').textContent = format(refund);
                    total += refund;
                });

                totalEl.textContent = format(total);
                headlineEl.textContent = format(total);

                // Mirrors LedgerService: clear the balance first, the rest is cash.
                const applied = Math.min(owed, total);
                appliedEl.textContent = format(applied);
                cashEl.textContent = format(total - applied);

                saveButton.disabled = total === 0;
            }

            table.addEventListener('input', (event) => {
                if (event.target.dataset.role === 'qty') recalculate();
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
