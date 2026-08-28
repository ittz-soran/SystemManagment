{{--
    One box for the whole list, filled from whichever pencil was pressed.

    The same shape the new-adjustment box has, minus the product: an adjustment
    is a note about one product's shelf, and pointing it at a different product
    is two documents rather than an edit.

    Section 8's reverse-and-re-apply happens on the way in, in the service —
    the whole original is undone and the new figures applied, so the batches end
    up as they would have if it had been right the first time.
--}}
<div class="modal fade" id="adjustment-edit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" data-guard-submit>
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Edit adjustment') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="form-label">{{ __('Product') }}</div>
                    <div class="form-control-plaintext" data-role="product">—</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label for="adj-edit-direction" class="form-label">{{ __('Direction') }}</label>
                        <select id="adj-edit-direction" name="direction" class="form-select">
                            <option value="out">{{ __('Out — remove stock') }}</option>
                            <option value="in">{{ __('In — add stock') }}</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label for="adj-edit-quantity" class="form-label">{{ __('Quantity') }}</label>
                        <input id="adj-edit-quantity" type="number" step="1" min="1" name="quantity"
                               class="form-control text-end" dir="ltr"
                               data-numpad="{{ __('Quantity') }}" data-numpad-min="1" required>
                    </div>
                </div>

                {{-- Section 4: FIFO needs a cost for every unit, so an incoming
                     adjustment cannot leave this blank. Going out, the cost
                     comes from the batches consumed. --}}
                <div class="mb-3 d-none" id="adj-edit-cost-wrap">
                    <label for="adj-edit-cost" class="form-label">{{ __('Cost each') }}</label>
                    <div class="input-group">
                        <input id="adj-edit-cost" type="number" step="1" min="0" name="unit_cost"
                               class="form-control text-end" dir="ltr" data-numpad="{{ __('Cost each') }}">
                        <span class="input-group-text">{{ __('IQD') }}</span>
                    </div>
                    <div class="form-text">{{ __('Required when adding stock — FIFO needs a cost for every unit.') }}</div>
                </div>

                <div class="mb-3">
                    <label for="adj-edit-reason" class="form-label">{{ __('Reason') }}</label>
                    <select id="adj-edit-reason" name="reason" class="form-select" required>
                        @foreach($reasons as $reason)
                            <option value="{{ $reason }}">{{ Str::headline($reason) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label for="adj-edit-date" class="form-label">{{ __('Date') }}</label>
                    <input id="adj-edit-date" type="date" name="adjusted_at" class="form-control" required>
                </div>

                <div>
                    <label for="adj-edit-notes" class="form-label">{{ __('Notes') }}</label>
                    <input id="adj-edit-notes" name="notes" class="form-control"
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

<script>
    (() => {
        const modal = document.getElementById('adjustment-edit');

        if (! modal) {
            return;
        }

        const form = modal.querySelector('form');
        const direction = form.querySelector('[name="direction"]');
        const costWrap = document.getElementById('adj-edit-cost-wrap');

        const showCost = () => costWrap.classList.toggle('d-none', direction.value !== 'in');

        direction.addEventListener('change', showCost);

        // Where to save, and what to open with: carried on the button that
        // opened it, so the same form serves every row.
        modal.addEventListener('show.bs.modal', (event) => {
            const opener = event.relatedTarget;

            if (! opener) {
                return;
            }

            form.action = opener.dataset.action ?? '';
            form.querySelector('[data-role="product"]').textContent = opener.dataset.product ?? '';

            direction.value = opener.dataset.direction ?? 'out';
            form.querySelector('[name="quantity"]').value = opener.dataset.quantity ?? '';
            form.querySelector('[name="unit_cost"]').value = opener.dataset.cost ?? '';
            form.querySelector('[name="reason"]').value = opener.dataset.reason ?? '';
            form.querySelector('[name="adjusted_at"]').value = opener.dataset.date ?? '';
            form.querySelector('[name="notes"]').value = opener.dataset.notes ?? '';

            showCost();
        });
    })();
</script>
