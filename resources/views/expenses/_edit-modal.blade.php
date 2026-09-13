{{--
    One modal for the whole list, filled from whichever pencil was pressed.

    The same shape the suppliers and customers use, with this screen's own
    fields — an expense is a title, a category, an amount and a date, and all
    four are things somebody types wrong.
--}}
<div class="modal fade" id="expense-edit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" data-guard-submit>
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Edit expense') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="expense-edit-title" class="form-label">{{ __('Title') }}</label>
                    <input id="expense-edit-title" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="expense-edit-category" class="form-label">{{ __('Category') }}</label>
                    <select id="expense-edit-category" name="expense_category_id" class="form-select" required>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="expense-edit-amount" class="form-label">{{ __('Amount') }}</label>
                    <x-money-input id="expense-edit-amount" name="amount" :lens="$lens" :min="1"
                                   data-numpad="{{ __('Amount') }}" data-numpad-min="1" required />
                </div>
                <div class="mb-3">
                    <label for="expense-edit-date" class="form-label">{{ __('Date') }}</label>
                    <input id="expense-edit-date" type="date" name="expense_date" class="form-control" required>
                </div>
                <div>
                    <label for="expense-edit-notes" class="form-label">{{ __('Notes') }}</label>
                    <input id="expense-edit-notes" name="notes" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save expense') }}</button>
            </div>
        </form>
    </div>
</div>

<script>
    (() => {
        const modal = document.getElementById('expense-edit');

        if (! modal) {
            return;
        }

        const form = modal.querySelector('form');

        // Where to save, and what to open with: carried on the button that
        // opened it, so the same form serves every row.
        modal.addEventListener('show.bs.modal', (event) => {
            const opener = event.relatedTarget;

            if (! opener) {
                return;
            }

            form.action = opener.dataset.action ?? '';

            ['title', 'amount', 'notes'].forEach((field) => {
                const value = opener.dataset[field] ?? '';
                form.querySelector(`[name="${field}"]`).value = value;

                /*
                 * ⚠️ And the companion field, when the box is taking another
                 * currency. It records what the box was FILLED with, so a save
                 * that never touched it keeps the stored figure exactly —
                 * otherwise the rounding of the conversion is written back.
                 * See App\Support\MoneyInput.
                 */
                const shown = form.querySelector(`[name="${field}_shown"]`);

                if (shown) {
                    shown.value = value;
                }
            });

            form.querySelector('[name="expense_category_id"]').value = opener.dataset.category ?? '';
            form.querySelector('[name="expense_date"]').value = opener.dataset.date ?? '';
        });
    })();
</script>
