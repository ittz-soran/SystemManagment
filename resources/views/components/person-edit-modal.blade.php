@props(['id', 'title', 'save'])

{{--
    One modal for the whole list, filled from whichever pencil was pressed.

    A supplier and a customer are the same three fields, and both are created in
    a modal already — so they are corrected in one too, rather than in a screen
    that exists only to be left again. One modal rather than one per row: a page
    of twenty-five suppliers would otherwise carry twenty-five copies of the
    same form.
--}}
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" data-guard-submit>
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="{{ $id }}-name" class="form-label">{{ __('Name') }}</label>
                    <input id="{{ $id }}-name" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="{{ $id }}-phone" class="form-label">{{ __('Phone') }}</label>
                    <input id="{{ $id }}-phone" name="phone" class="form-control" dir="ltr">
                </div>
                <div>
                    <label for="{{ $id }}-address" class="form-label">{{ __('Address') }}</label>
                    <input id="{{ $id }}-address" name="address" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ $save }}</button>
            </div>
        </form>
    </div>
</div>

<script>
    (() => {
        const modal = document.getElementById(@json($id));

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

            ['name', 'phone', 'address'].forEach((field) => {
                form.querySelector(`[name="${field}"]`).value = opener.dataset[field] ?? '';
            });
        });
    })();
</script>
