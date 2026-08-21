@props(['action', 'confirm' => null])

{{-- Section 8b: bulk delete is a loop of the normal single-delete logic, and it
     reports what it skipped. So the bar promises a count, not a result — the
     flash message afterwards says how many actually went.

     The form sits outside the table rather than wrapping it, because rows carry
     their own delete forms and HTML forbids nesting them. --}}
<form method="POST" action="{{ $action }}" id="bulk-form" class="d-none" data-guard-submit>
    @csrf
    @method('DELETE')
    <div id="bulk-ids"></div>
</form>

<div class="position-sticky bottom-0 pt-3 d-none" id="bulk-bar" style="z-index: 5">
    <div class="card card-body shadow-sm py-2 d-flex flex-row align-items-center gap-3">
        <span class="fw-medium" id="bulk-count"></span>

        <button type="button" class="btn btn-sm btn-outline-danger" id="bulk-delete">
            <i class="bi bi-trash me-1"></i>{{ __('Delete selected') }}
        </button>

        <button type="button" class="btn btn-sm btn-link text-decoration-none ms-auto" id="bulk-clear">
            {{ __('Clear selection') }}
        </button>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            const bar = document.getElementById('bulk-bar');
            const countEl = document.getElementById('bulk-count');
            const selectAll = document.getElementById('bulk-select-all');
            const form = document.getElementById('bulk-form');
            const idsBox = document.getElementById('bulk-ids');

            const boxes = () => [...document.querySelectorAll('[data-bulk-id]')];
            const checked = () => boxes().filter((b) => b.checked);

            function refresh() {
                const selected = checked();

                bar.classList.toggle('d-none', selected.length === 0);
                countEl.textContent = @json(__(':count selected')).replace(':count', selected.length);

                if (selectAll) {
                    selectAll.checked = selected.length > 0 && selected.length === boxes().length;
                    selectAll.indeterminate = selected.length > 0 && selected.length < boxes().length;
                }
            }

            document.addEventListener('change', (event) => {
                if (event.target.matches('[data-bulk-id]')) refresh();
            });

            selectAll?.addEventListener('change', () => {
                boxes().forEach((box) => { box.checked = selectAll.checked; });
                refresh();
            });

            document.getElementById('bulk-clear').addEventListener('click', () => {
                boxes().forEach((box) => { box.checked = false; });
                refresh();
            });

            document.getElementById('bulk-delete').addEventListener('click', () => {
                const selected = checked();
                if (selected.length === 0) return;

                const question = @json($confirm ?? __('Delete the selected records?'));

                if (! confirm(question + '\n\n' +
                    @json(__('Locked records are skipped and reported, not deleted.')))) return;

                idsBox.innerHTML = '';

                selected.forEach((box) => {
                    const field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'ids[]';
                    field.value = box.dataset.bulkId;
                    idsBox.appendChild(field);
                });

                form.requestSubmit();
            });

            refresh();
        })();
    </script>
@endpush
