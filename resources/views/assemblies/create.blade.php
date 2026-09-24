@extends('layouts.app')

@section('title', $direction === 'apart' ? __('Take something apart') : __('Build from parts'))

@section('back')
    <x-back-link :to="route('assemblies.index')" :label="__('Take apart & build')" remember="assemblies" permission="assemblies.view" />
@endsection

@section('actions')
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    {{-- The two directions are one document read from different ends, so this
         is one form with the sides swapped rather than two screens. --}}
    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $direction === 'apart' ? 'active' : '' }}"
               href="{{ route('assemblies.create', ['direction' => 'apart']) }}">
                <i class="bi bi-box-arrow-down me-1"></i>{{ __('Take apart') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $direction === 'together' ? 'active' : '' }}"
               href="{{ route('assemblies.create', ['direction' => 'together']) }}">
                <i class="bi bi-boxes me-1"></i>{{ __('Build from parts') }}
            </a>
        </li>
    </ul>

    <form action="{{ route('assemblies.store') }}" method="POST" id="assembly-form" data-guard-submit>
        @csrf
        <input type="hidden" name="direction" value="{{ $direction }}">

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        {{ $direction === 'apart' ? __('What goes in') : __('What comes out') }}
                    </div>
                    <div class="card-body">
                        @if($direction === 'apart')
                            <div class="mb-3">
                                <label for="whole-product" class="form-label">{{ __('The thing you are taking apart') }}</label>
                                <select id="whole-product" name="whole[product_id]" class="form-select" required data-role="source">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach($available as $item)
                                        <option value="{{ $item['id'] }}" data-batches="{{ json_encode($item['batches']) }}">
                                            {{ $item['name'] }} — {{ $item['quantity'] }} {{ $item['unit'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($available->isEmpty())
                                    <div class="form-text text-danger">
                                        {{ __('Nothing on the shelf to take apart yet.') }}
                                    </div>
                                @endif
                            </div>

                            <div class="mb-3">
                                <label for="whole-quantity" class="form-label">{{ __('How many') }}</label>
                                <input id="whole-quantity" type="number" name="whole[quantity]" dir="ltr"
                                       class="form-control text-end" min="1" step="1" value="1" required
                                       data-role="source-qty">
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">{{ __('What it cost you') }}</span>
                                <span class="money fs-5 fw-semibold" id="source-total">0</span>
                            </div>
                            <div class="small text-secondary">
                                {{ __('Read from the batches it will actually come out of, not from a price on the product.') }}
                            </div>
                        @else
                            <div class="mb-3">
                                <label for="whole-name" class="form-label">{{ __('What you are building') }}</label>
                                <input id="whole-name" name="whole[name]" class="form-control" maxlength="255"
                                       value="{{ old('whole.name') }}" list="known-products"
                                       placeholder="{{ __('Gaming PC build #3') }}" required>
                                <datalist id="known-products">
                                    @foreach($products as $product)
                                        <option value="{{ $product->name }}"></option>
                                    @endforeach
                                </datalist>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label for="whole-quantity" class="form-label">{{ __('How many') }}</label>
                                    <input id="whole-quantity" type="number" name="whole[quantity]" dir="ltr"
                                           class="form-control text-end" min="1" step="1" value="1" required>
                                </div>
                                <div class="col-6">
                                    <label for="whole-price" class="form-label">{{ __('Sell it for') }}</label>
                                    <x-money-input name="whole[sale_price]" id="whole-price" :value="old('whole.sale_price')" />
                                </div>
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">{{ __('It will cost you') }}</span>
                                <span class="money fs-5 fw-semibold" id="source-total">0</span>
                            </div>
                            <div class="small text-secondary">
                                {{-- ⚠️ Said plainly, because it is the one rule
                                     of this direction: the cost is not typed. --}}
                                {{ __('The sum of the parts. You do not type this — a machine is worth what its parts cost.') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>{{ $direction === 'apart' ? __('What comes out') : __('What goes in') }}</span>
                        <span class="d-flex gap-2">
                            @if($direction === 'apart')
                                <button type="button" class="btn btn-sm btn-outline-primary" id="share-out">
                                    {{ __('Share the cost by price') }}
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-piece">
                                <i class="bi bi-plus-lg me-1"></i>{{ __('Add a line') }}
                            </button>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-pieces align-middle mb-0" id="pieces">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money" style="width: 6rem">{{ __('Quantity') }}</th>
                                @if($direction === 'apart')
                                    <th class="money" style="width: 9rem">{{ __('Cost each') }}</th>
                                    <th class="money" style="width: 9rem">{{ __('Sell it for') }}</th>
                                @endif
                                <th style="width: 3rem"></th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    @if($direction === 'apart')
                        <div class="card-footer">
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">{{ __('The pieces come to') }}</span>
                                <span class="money" id="pieces-total">0</span>
                            </div>
                            <div class="d-flex justify-content-between fw-semibold">
                                <span id="remainder-label">{{ __('Still to account for') }}</span>
                                <span class="money" id="remainder">0</span>
                            </div>
                            <div class="small text-secondary mt-1" id="balance-note">
                                {{-- ⚠️ The rule of this screen, where the number
                                     it refers to is. --}}
                                {{ __('The pieces must be worth exactly what went in. Nothing is earned or lost by opening a box.') }}
                            </div>
                        </div>
                    @endif
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <label for="note" class="form-label">{{ __('Note') }}</label>
                        <input id="note" name="note" class="form-control" maxlength="500"
                               value="{{ old('note') }}"
                               placeholder="{{ __('Customer wanted only one controller') }}">
                    </div>
                </div>

                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-lg" id="save"
                            data-submitting-text="{{ __('Saving…') }}">
                        {{ $direction === 'apart' ? __('Take it apart') : __('Build it') }}
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{--
        The rows are built in JavaScript, so the choices go down as data.

        ⚠️ Assembled in a block above and handed to `@@json` as ONE variable.
        Written inline as `@@json([...])`, Blade's paren matcher stops at the
        first nested `[` and the compiled view dies with "Unclosed '['" — see
        the Blade traps in the project doc. Caught here by a test that renders
        the page, which is the only thing that can catch it.
    --}}
    @php
        $assemblyData = [
            'direction' => $direction,
            'available' => $available,
            'products' => $products,
            'shareUrl' => route('assemblies.share'),
            'labels' => [
                'choose' => __('Choose…'),
                'orType' => __('or type a new name'),
                'remove' => __('Remove'),
                'balanced' => __('It balances. Ready to save.'),
                'over' => __('Too much by'),
                'under' => __('Still to account for'),
                'costEach' => __('Cost each'),
                'sellFor' => __('Sell it for'),
                'quantity' => __('Quantity'),
            ],
        ];
    @endphp

    <script type="application/json" id="assembly-data">
        @json($assemblyData)
    </script>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.getElementById('assembly-form');
            if (! form) return;

            const data = JSON.parse(document.getElementById('assembly-data').textContent);
            const apart = data.direction === 'apart';
            const body = document.querySelector('#pieces tbody');
            const lens = @json($lens?->forScript());
            const format = (n) => window.appMoneyIn(n, lens);

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            /*
             * What the source side will actually cost, walked out of the
             * batches in FIFO order — the same walk the server will do, so the
             * remainder on screen is the remainder the balance check will see.
             */
            function fifoCost(batches, wanted) {
                let left = wanted;
                let cost = 0;

                for (const batch of batches ?? []) {
                    if (left <= 0) break;
                    const take = Math.min(left, batch.left);
                    cost += take * batch.cost;
                    left -= take;
                }

                // Asking for more than there is: the server refuses, and until
                // then the figure should not pretend the rest is free.
                return left > 0 ? null : cost;
            }

            function sourceTotal() {
                if (apart) {
                    const select = document.querySelector('[data-role="source"]');
                    const option = select?.selectedOptions?.[0];
                    if (! option || ! option.value) return 0;

                    const wanted = Number(document.querySelector('[data-role="source-qty"]')?.value || 0);

                    return fifoCost(JSON.parse(option.dataset.batches || '[]'), wanted);
                }

                // Building: the source side is the rows, and each row's cost
                // comes from its own batches.
                let total = 0;

                for (const row of body.querySelectorAll('tr')) {
                    const option = row.querySelector('[data-role="piece-product"]')?.selectedOptions?.[0];
                    if (! option || ! option.value) continue;

                    const wanted = Number(row.querySelector('[data-role="piece-qty"]')?.value || 0);
                    const cost = fifoCost(JSON.parse(option.dataset.batches || '[]'), wanted);

                    if (cost === null) return null;
                    total += cost;
                }

                return total;
            }

            function row(index) {
                const tr = document.createElement('tr');

                // Taking apart: a piece may land in a product the shop already
                // has, or in a new one typed here. Building: it must be
                // something on the shelf, so only the list.
                const chooser = apart
                    ? `<input name="pieces[${index}][name]" class="form-control form-control-sm mb-1"
                              list="known-products" placeholder="${data.labels.orType}">
                       <select name="pieces[${index}][product_id]" class="form-select form-select-sm">
                           <option value="">${data.labels.choose}</option>
                           ${data.products.map((p) => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('')}
                       </select>`
                    : `<select name="pieces[${index}][product_id]" class="form-select form-select-sm"
                               data-role="piece-product" required>
                           <option value="">${data.labels.choose}</option>
                           ${data.available.map((p) => `<option value="${p.id}" data-batches='${JSON.stringify(p.batches)}'>${escapeHtml(p.name)} — ${p.quantity} ${escapeHtml(p.unit ?? '')}</option>`).join('')}
                       </select>`;

                /*
                 * ⚠️ Each cell carries its own label, shown only on a phone.
                 * Below `sm` the header is clipped and the row becomes a grid —
                 * and three unlabelled number boxes in a line is a guessing
                 * game between what a piece COST and what it SELLS for, which
                 * are the two figures on this screen that must not be confused.
                 */
                const label = (text) => `<span class="piece-label d-sm-none">${text}</span>`;

                const costCells = apart
                    ? `<td class="piece-cell-cost">${label(data.labels.costEach)}
                          <input type="number" name="pieces[${index}][unit_cost]" dir="ltr" min="0" step="1"
                                 class="form-control form-control-sm text-end" value="0" data-role="piece-cost"></td>
                       <td class="piece-cell-price">${label(data.labels.sellFor)}
                          <input type="number" name="pieces[${index}][sale_price]" dir="ltr" min="0" step="1"
                                 class="form-control form-control-sm text-end" value="0" data-role="piece-price"></td>`
                    : '';

                tr.innerHTML = `
                    <td class="piece-cell-product">${chooser}</td>
                    <td class="piece-cell-qty">${label(data.labels.quantity)}
                        <input type="number" name="pieces[${index}][quantity]" dir="ltr" min="1" step="1"
                              class="form-control form-control-sm text-end" value="1" data-role="piece-qty" required></td>
                    ${costCells}
                    <td class="piece-cell-actions text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger" data-role="drop"
                                aria-label="${data.labels.remove}"><i class="bi bi-x-lg"></i></button>
                    </td>`;

                return tr;
            }

            function escapeHtml(text) {
                const box = document.createElement('div');
                box.textContent = text ?? '';

                return box.innerHTML;
            }

            function add() {
                body.appendChild(row(body.children.length));
                recalculate();
            }

            function recalculate() {
                const total = sourceTotal();
                document.getElementById('source-total').textContent = total === null ? '—' : format(total);

                if (! apart) {
                    document.getElementById('save').disabled = total === null || total <= 0;

                    return;
                }

                let pieces = 0;

                for (const input of body.querySelectorAll('[data-role="piece-cost"]')) {
                    const qty = Number(input.closest('tr').querySelector('[data-role="piece-qty"]').value || 0);
                    pieces += qty * Number(input.value || 0);
                }

                document.getElementById('pieces-total').textContent = format(pieces);

                const left = (total ?? 0) - pieces;
                document.getElementById('remainder').textContent = format(Math.abs(left));
                document.getElementById('remainder-label').textContent =
                    left < 0 ? data.labels.over : data.labels.under;

                const note = document.getElementById('balance-note');
                const balanced = total !== null && total > 0 && left === 0 && body.children.length > 0;

                note.classList.toggle('text-success', balanced);
                if (balanced) note.textContent = data.labels.balanced;

                document.getElementById('save').disabled = ! balanced;
            }

            document.getElementById('add-piece').addEventListener('click', add);

            body.addEventListener('click', (event) => {
                if (event.target.closest('[data-role="drop"]')) {
                    event.target.closest('tr').remove();
                    recalculate();
                }
            });

            form.addEventListener('input', recalculate);
            form.addEventListener('change', recalculate);

            const shareButton = document.getElementById('share-out');

            if (shareButton) {
                shareButton.addEventListener('click', async () => {
                    const total = sourceTotal();
                    if (! total) return;

                    const rows = [...body.querySelectorAll('tr')];

                    // What each line will SELL for, which is the ratio to split
                    // the cost by. The server decides where the odd dinar goes.
                    const lines = rows.map((tr) => ({
                        value: Number(tr.querySelector('[data-role="piece-price"]').value || 0),
                        quantity: Math.max(1, Number(tr.querySelector('[data-role="piece-qty"]').value || 1)),
                    }));

                    const response = await fetch(data.shareUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ total, lines }),
                    });

                    if (! response.ok) return;

                    // Costs PER UNIT, ready to drop straight in — the server
                    // has already walked the remainder out in whole units.
                    const { costs } = await response.json();

                    rows.forEach((tr, index) => {
                        tr.querySelector('[data-role="piece-cost"]').value = costs[index];
                    });

                    recalculate();
                });
            }

            add();
            recalculate();
        })();
    </script>
@endpush
