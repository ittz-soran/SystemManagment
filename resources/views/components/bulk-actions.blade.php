@props(['action' => null, 'export' => null, 'move' => null, 'categories' => null, 'confirm' => null])

{{-- Section 8b: what to do with the rows that are ticked.

     Bulk delete is a loop of the normal single-delete logic and it reports what
     it skipped, so the bar promises a count, not a result — the flash message
     afterwards says how many actually went.

     Every form here sits OUTSIDE the table rather than wrapping it, and that is
     not a preference. Rows carry their own delete forms, HTML forbids nesting
     one form inside another, and a browser meeting that simply drops the inner
     tag: the row's delete button then belongs to the outer form and its spoofed
     DELETE is posted to whatever that form's action happens to be. A row delete
     that quietly 404s somewhere else is the result. The ids are carried into
     these forms by hand instead. --}}
@if($action)
    <form method="POST" action="{{ $action }}" id="bulk-form" class="d-none" data-guard-submit>
        @csrf
        @method('DELETE')
        <div id="bulk-ids"></div>
    </form>
@endif

@if($move)
    <form method="POST" action="{{ $move }}" id="bulk-move-form" class="d-none" data-guard-submit>
        @csrf
        <input type="hidden" name="category_id" id="bulk-move-category">
        <div id="bulk-move-ids"></div>
    </form>
@endif

{{-- The chosen rows as the same CSV the import/export screen writes, so a file
     taken out here can be edited and brought back in. No confirmation and no
     hold: nothing is changed by taking a copy. --}}
@if($export)
    <form method="POST" action="{{ $export }}" id="bulk-export-form" class="d-none">
        @csrf
        <div id="bulk-export-ids"></div>
    </form>
@endif

<div class="position-sticky bottom-0 pt-3 d-none" id="bulk-bar" style="z-index: 5">
    <div class="card card-body shadow-sm py-2 d-flex flex-row align-items-center gap-3">
        <span class="fw-medium" id="bulk-count"></span>

        @if($move && $categories)
            <div class="dropdown">
                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle"
                        data-bs-toggle="dropdown">
                    <i class="bi bi-tags me-1"></i>{{ __('Move to category…') }}
                </button>
                <ul class="dropdown-menu">
                    @foreach($categories as $category)
                        <li>
                            <button type="button" class="dropdown-item" data-move-to="{{ $category->id }}">
                                {{ $category->name }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($export)
            <button type="button" class="btn btn-sm btn-outline-secondary" id="bulk-export">
                <i class="bi bi-download me-1"></i>{{ __('Export selected') }}
            </button>
        @endif

        @if($action)
            <button type="button" class="btn btn-sm btn-outline-danger" id="bulk-delete">
                <i class="bi bi-trash me-1"></i>{{ __('Delete selected') }}
            </button>
        @endif

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

            /**
             * What is ticked, remembered across pages.
             *
             * Twenty rows to a page and forty to delete used to mean doing it
             * twice: turning to page two dropped everything ticked on page one,
             * silently, and the bar came back saying nothing was selected.
             *
             * The ids live in this tab's own storage, under the path of the
             * list they belong to, so the products list and the sales list do
             * not borrow each other's ticks. They last until the selection is
             * used or cleared, and they die with the tab.
             */
            const KEY = 'bulk:' + location.pathname;

            function remembered() {
                try {
                    return new Set(JSON.parse(sessionStorage.getItem(KEY) ?? '[]'));
                } catch {
                    return new Set();
                }
            }

            function remember(ids) {
                try {
                    if (ids.size === 0) sessionStorage.removeItem(KEY);
                    else sessionStorage.setItem(KEY, JSON.stringify([...ids]));
                } catch { /* a private window; the page still works */ }
            }

            function forget() {
                remember(new Set());
            }

            function refresh() {
                const onPage = boxes();
                const held = remembered();

                // What is on screen decides what is remembered, for these rows
                // only; anything ticked on another page is left alone.
                onPage.forEach((box) => {
                    if (box.checked) held.add(box.dataset.bulkId);
                    else held.delete(box.dataset.bulkId);
                });

                remember(held);

                bar.classList.toggle('d-none', held.size === 0);
                countEl.textContent = @json(__(':count selected')).replace(':count', held.size);

                const here = onPage.filter((b) => b.checked).length;

                if (selectAll) {
                    selectAll.checked = here > 0 && here === onPage.length;
                    selectAll.indeterminate = here > 0 && here < onPage.length;
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
                forget();
                refresh();
            });

            // Arriving on page two with page one still ticked.
            (() => {
                const held = remembered();

                boxes().forEach((box) => {
                    if (held.has(box.dataset.bulkId)) box.checked = true;
                });
            })();

            /**
             * Copy the whole selection into a form's hidden fields — everything
             * remembered, not only the rows that happen to be on screen.
             */
            function carry(into, name = 'ids[]') {
                into.innerHTML = '';

                remembered().forEach((id) => {
                    const field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = name;
                    field.value = id;
                    into.appendChild(field);
                });
            }

            document.querySelectorAll('[data-move-to]').forEach((choice) => {
                choice.addEventListener('click', () => {
                    if (remembered().size === 0) return;

                    // The move form wants product_ids[], not ids[] — it is the
                    // same endpoint the category screen posts to.
                    carry(document.getElementById('bulk-move-ids'), 'product_ids[]');
                    document.getElementById('bulk-move-category').value = choice.dataset.moveTo;
                    forget();
                    document.getElementById('bulk-move-form').requestSubmit();
                });
            });

            document.getElementById('bulk-export')?.addEventListener('click', () => {
                if (remembered().size === 0) return;

                // Taking a copy changes nothing, so the ticks stay put.
                carry(document.getElementById('bulk-export-ids'));
                document.getElementById('bulk-export-form').requestSubmit();
            });

            document.getElementById('bulk-delete')?.addEventListener('click', () => {
                if (remembered().size === 0) return;

                const question = @json($confirm ?? __('Delete the selected records?'))
                    .replace(':count', remembered().size);

                if (! confirm(question + '\n\n' +
                    @json(__('Locked records are skipped and reported, not deleted.')))) return;

                carry(idsBox);
                forget();
                form.requestSubmit();
            });

            refresh();
        })();
    </script>
@endpush
