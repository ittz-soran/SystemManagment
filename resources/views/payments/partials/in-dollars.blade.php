{{--
    A payment on a purchase owed in dollars — PROJECT_DOC Section 6, Soran,
    2026-11-02: *"when i pay, then the system should keep the dinars against the
    dollars"*.

    Typed in the purchase's own currency, at the rate of the day it is paid.
    The sum under the boxes is what the server will work out, said before the
    save: the dinars that leave the till, the dinars of the purchase they
    settle, and the difference — the line the profit report carries.

    $owed  from PaymentController::owedInDollars
    $value the dollars the box starts at, in cents
    $rate  the rate the box starts at
--}}
@php
    $currency = $owed['currency'];
    $places = $currency->decimals;
    $per = $currency->minorPerMajor();
    $step = $places === 0 ? '1' : '0.'.str_repeat('0', $places - 1).'1';
    $shown = str_replace(',', '', App\Support\Money::writeAt((int) $value, $places));
@endphp

<div class="mb-3">
    <label for="foreign_amount" class="form-label">{{ __('Amount') }}</label>
    <div class="input-group has-validation">
        <input id="foreign_amount" type="number" name="foreign_amount" dir="ltr" required autofocus
               step="{{ $step }}" min="{{ $step }}"
               class="form-control text-end @error('foreign_amount') is-invalid @enderror"
               value="{{ old('foreign_amount', $shown) }}"
               data-numpad="{{ __('Amount') }}" data-numpad-decimals="{{ $places }}">
        <span class="input-group-text app-code">{{ $currency->mark() }}</span>
        @error('foreign_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-text">
        {{ __('This purchase is owed in :code. Type what you paid in :code.', ['code' => $currency->code]) }}
    </div>
</div>

<div class="mb-3">
    <label for="exchange_rate" class="form-label">{{ __('Rate it was paid at') }}</label>
    <div class="input-group has-validation">
        <span class="input-group-text app-code">1 {{ $currency->code }} =</span>
        <input id="exchange_rate" type="number" name="exchange_rate" dir="ltr" required step="1" min="1"
               class="form-control text-end @error('exchange_rate') is-invalid @enderror"
               value="{{ old('exchange_rate', $rate) }}"
               data-numpad="{{ __('Rate it was paid at') }}">
        <span class="input-group-text app-code">{{ App\Support\Money::base()->mark() }}</span>
        @error('exchange_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-text">
        {{ __('The rate on the day you paid. The purchase was written at :rate.', ['rate' => number_format($owed['purchaseRate'])]) }}
    </div>
</div>

<ul class="list-group mb-3 small" id="dollar-sum" dir="auto"
    data-per="{{ $per }}" data-due="{{ $owed['due'] }}" data-due-dinars="{{ $owed['dueInDinars'] }}"
    data-purchase-rate="{{ $owed['purchaseRate'] }}">
    <li class="list-group-item d-flex justify-content-between">
        <span>{{ __('Leaves the till') }}</span>
        <span class="money fw-semibold" data-sum="out">—</span>
    </li>
    <li class="list-group-item d-flex justify-content-between">
        <span class="text-secondary">{{ __('Settles of this purchase, at its own rate') }}</span>
        <span class="money" data-sum="settles">—</span>
    </li>
    <li class="list-group-item d-flex justify-content-between">
        <span class="text-secondary">{{ __('Dollar rate difference') }}</span>
        <span class="money" data-sum="difference">—</span>
    </li>
</ul>

@push('scripts')
    <script>
        // What the server will work out, shown as it is typed — the same rule
        // as Purchase::settle. The server works it out again; this only says it.
        (() => {
            const sum = document.getElementById('dollar-sum');
            const amount = document.getElementById('foreign_amount');
            const rate = document.getElementById('exchange_rate');
            if (! sum || ! amount || ! rate) return;

            const per = Number(sum.dataset.per);
            const due = Number(sum.dataset.due);
            const dueDinars = Number(sum.dataset.dueDinars);
            const purchaseRate = Number(sum.dataset.purchaseRate);
            const mark = @json(App\Support\Money::base()->mark());
            const words = {
                loss: @json(__(':amount more than the purchase cost — a loss')),
                gain: @json(__(':amount less than the purchase cost — a gain')),
                none: @json(__('None')),
                tooMuch: @json(__('More than is owed')),
            };
            const write = (n) => window.appMoney(n) + ' ' + mark;
            const cell = (name) => sum.querySelector(`[data-sum="${name}"]`);

            function update() {
                const minor = Math.round(Number(amount.value || 0) * per);
                const r = Number(rate.value || 0);

                if (! minor || ! r) {
                    ['out', 'settles', 'difference'].forEach((n) => { cell(n).textContent = '—'; });
                    return;
                }

                if (minor > due) {
                    cell('out').textContent = write(Math.round(minor * r / per));
                    cell('settles').textContent = words.tooMuch;
                    cell('difference').textContent = '—';
                    return;
                }

                const out = Math.round(minor * r / per);
                const settles = minor === due
                    ? Math.max(0, dueDinars)
                    : Math.max(0, Math.min(Math.round(minor * purchaseRate / per), dueDinars - 1));
                const difference = out - settles;

                cell('out').textContent = write(out);
                cell('settles').textContent = write(settles);
                cell('difference').textContent = difference === 0
                    ? words.none
                    : (difference > 0 ? words.loss : words.gain).replace(':amount', write(Math.abs(difference)));
                cell('difference').classList.toggle('text-danger', difference > 0);
                cell('difference').classList.toggle('text-success', difference < 0);
            }

            amount.addEventListener('input', update);
            rate.addEventListener('input', update);
            update();
        })();
    </script>
@endpush
