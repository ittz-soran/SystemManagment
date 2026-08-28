@extends('layouts.app')

@section('title', __('Stock adjustments'))
@section('subheading', __('The only way to correct a document that is already locked'))

@section('actions')
    @can('stock_adjustments.create')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjustment-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New adjustment') }}
        </button>
    @endcan
    @can('stock.recheck')
        <a href="{{ route('stock.recheck') }}" class="btn btn-outline-secondary">
            <i class="bi bi-clipboard-check me-1"></i>{{ __('Recheck stock') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-archived-notice :count="$archivedCount" />

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Document') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="ADJ-">
            </div>
            <div class="col-md-2">
                <label for="direction" class="form-label small">{{ __('Direction') }}</label>
                <select id="direction" name="direction" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="in" @selected(request('direction') === 'in')>{{ __('In') }}</option>
                    <option value="out" @selected(request('direction') === 'out')>{{ __('Out') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="reason" class="form-label small">{{ __('Reason') }}</label>
                <select id="reason" name="reason" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($reasons as $reason)
                        <option value="{{ $reason }}" @selected(request('reason') === $reason)>
                            {{ Str::headline($reason) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('stock-adjustments.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($adjustments->isEmpty())
        <div class="card">
            <x-empty-state icon="sliders"
                           :message="__('No adjustments yet. Use one to write off damage, correct a miscount, or fix a locked document.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th>{{ __('By') }}</th>
                        <th class="money">{{ __('Quantity') }}</th>
                        <th class="money">{{ __('Cost each') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($adjustments as $adjustment)
                        <tr>
                            <td class="fw-medium">
                                <x-document-link :document="$adjustment" :kind="false" />
                            </td>
                            <td dir="ltr" class="small">{{ $adjustment->adjusted_at->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td>
                                <a href="{{ route('products.show', $adjustment->product) }}" class="text-decoration-none">
                                    {{ $adjustment->product->name }}
                                </a>
                            </td>
                            <td>
                                <span class="badge text-bg-light">{{ Str::headline($adjustment->reason) }}</span>
                                @if($adjustment->notes)
                                    <div class="small text-secondary">{{ $adjustment->notes }}</div>
                                @endif
                            </td>
                            <td class="small text-secondary">{{ $adjustment->user->name }}</td>
                            <td class="money fw-semibold {{ $adjustment->direction === 'in' ? 'text-success' : 'text-danger' }}">
                                {{ $adjustment->direction === 'in' ? '+' : '−' }}{{ number_format($adjustment->quantity) }}
                            </td>
                            {{-- Section 4: `out` has no typed cost — the value
                                 written off is the true FIFO cost of the batches
                                 it consumed. --}}
                            <td class="money text-secondary">
                                {{ $adjustment->unit_cost !== null ? cost_money($adjustment->unit_cost, false) : __('FIFO') }}
                            </td>
                            <td class="text-end">
                                {{-- Offered plainly, like the delete beside it: the
                                     engine refuses an edit whose units have since
                                     been sold, and the refusal explains itself. --}}
                                <x-row-actions
                                    :view="route('stock-adjustments.show', $adjustment)"
                                    :edit="Gate::allows('stock_adjustments.edit') ? route('stock-adjustments.edit', $adjustment) : null"
                                    :delete="Gate::allows('stock_adjustments.delete') ? route('stock-adjustments.destroy', $adjustment) : null"
                                    :delete-label="__('Delete :document? :count units go back the way they came.', [
                                        'document' => $adjustment->document_no,
                                        'count' => $adjustment->quantity,
                                    ])" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $adjustments->links() }}</div>
    @endif

    @can('stock_adjustments.create')
        <div class="modal fade" id="adjustment-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" action="{{ route('stock-adjustments.store') }}" method="POST" data-guard-submit>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New adjustment') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="adj-product" class="form-label">{{ __('Product') }}</label>
                            <input id="adj-product-search" type="text" class="form-control mb-2" data-english-digits
                                   placeholder="{{ __('Scan or type to find a product…') }}" autocomplete="off">
                            <div id="adj-results" class="list-group mb-2 d-none"></div>
                            <input type="hidden" name="product_id" id="adj-product">
                            <div id="adj-chosen" class="form-text"></div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="adj-direction" class="form-label">{{ __('Direction') }}</label>
                                <select id="adj-direction" name="direction" class="form-select">
                                    <option value="out">{{ __('Out — remove stock') }}</option>
                                    <option value="in">{{ __('In — add stock') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="adj-quantity" class="form-label">{{ __('Quantity') }}</label>
                                <input id="adj-quantity" type="number" step="1" min="1" name="quantity"
                                       class="form-control text-end" dir="ltr" required>
                            </div>
                        </div>

                        {{-- Section 4: FIFO needs a cost for every unit, so an
                             incoming adjustment cannot leave this blank. Going
                             out, the cost comes from the batches consumed. --}}
                        <div class="mb-3 d-none" id="adj-cost-wrap">
                            <label for="adj-cost" class="form-label">{{ __('Cost each') }}</label>
                            <div class="input-group">
                                <input id="adj-cost" type="number" step="1" min="0" name="unit_cost"
                                       class="form-control text-end" dir="ltr">
                                <span class="input-group-text">{{ __('IQD') }}</span>
                            </div>
                            <div class="form-text">{{ __('Required when adding stock — FIFO needs a cost for every unit.') }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="adj-reason" class="form-label">{{ __('Reason') }}</label>
                            <select id="adj-reason" name="reason" class="form-select" required>
                                @foreach($reasons as $reason)
                                    <option value="{{ $reason }}">{{ Str::headline($reason) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="adj-date" class="form-label">{{ __('Date') }}</label>
                            <input id="adj-date" type="date" name="adjusted_at" class="form-control"
                                   value="{{ today()->toDateString() }}" required>
                        </div>

                        <div>
                            <label for="adj-notes" class="form-label">{{ __('Notes') }}</label>
                            <input id="adj-notes" name="notes" class="form-control"
                                   placeholder="{{ __('What happened?') }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save adjustment') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection

@push('scripts')
    <script>
        (() => {
            const search = document.getElementById('adj-product-search');
            if (! search) return;

            const results = document.getElementById('adj-results');
            const hidden = document.getElementById('adj-product');
            const chosen = document.getElementById('adj-chosen');
            const direction = document.getElementById('adj-direction');
            const costWrap = document.getElementById('adj-cost-wrap');
            const cost = document.getElementById('adj-cost');

            let timer = null;

            function syncDirection() {
                const incoming = direction.value === 'in';
                costWrap.classList.toggle('d-none', ! incoming);
                cost.required = incoming;
                if (! incoming) cost.value = '';
            }

            direction.addEventListener('change', syncDirection);
            syncDirection();

            // Arrived from a product's own page: the product is already chosen
            // and the form is open, so the only thing left is the number.
            @if($startWith)
                hidden.value = @json($startWith->id);
                chosen.textContent = @json($startWith->name);
                search.value = @json($startWith->name);

                // app.js is a module, so it is deferred and window.bootstrap
                // does not exist yet while this inline script runs.
                document.addEventListener('DOMContentLoaded', () => {
                    bootstrap.Modal.getOrCreateInstance(
                        document.getElementById('adjustment-modal')
                    ).show();
                });
            @endif

            search.addEventListener('input', () => {
                clearTimeout(timer);
                const term = search.value.trim();

                if (term === '') {
                    results.classList.add('d-none');
                    return;
                }

                timer = setTimeout(async () => {
                    const response = await fetch(
                        // Ordinary stock only. A service has no stock behind it to
                        // correct, and a second-hand item is one machine bought on
                        // one document — what happens to it belongs on that
                        // document, not in a quantity correction.
                        `{{ route('products.search') }}?kinds=stock&q=${encodeURIComponent(term)}`,
                        { headers: { 'Accept': 'application/json' } },
                    );
                    if (! response.ok) return;

                    const data = await response.json();
                    results.innerHTML = '';

                    data.products.forEach((product) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                        item.innerHTML =
                            `<span>${escapeHtml(product.name)} <span class="small text-secondary ms-2" dir="ltr">${escapeHtml(product.sku)}</span></span>` +
                            `<span class="small text-secondary">${new Intl.NumberFormat('en-US').format(product.quantity)}</span>`;

                        item.addEventListener('click', () => {
                            hidden.value = product.id;
                            chosen.textContent =
                                @json(__('Chosen:')) + ' ' + product.name + ' — ' +
                                new Intl.NumberFormat('en-US').format(product.quantity) + ' ' +
                                @json(__('in stock'));
                            search.value = '';
                            results.classList.add('d-none');
                        });

                        results.appendChild(item);
                    });

                    results.classList.toggle('d-none', data.products.length === 0);
                }, 150);
            });
        })();
    </script>
@endpush
