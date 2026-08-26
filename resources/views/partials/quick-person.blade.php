{{--
    Somebody new, added without leaving the cart.

    A customer walks in who has never been in before, or a supplier turns up
    with goods and no record. Losing twenty-five scanned lines to go and make
    the record first is not a trade anybody should have to accept.

    Posted on its own rather than with the cart around it: this is a separate
    form, sent by fetch, so the cart is never submitted by accident. The new
    person is added to the select and chosen.

    Expects: $id ('customer'|'supplier'), $storeRoute, $selectId.
--}}
<div class="modal fade" id="new-{{ $id }}-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    {{ $id === 'customer' ? __('New customer') : __('New supplier') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="new-{{ $id }}-error"></div>

                <div class="mb-3">
                    <label for="new-{{ $id }}-name" class="form-label">{{ __('Name') }}</label>
                    <input id="new-{{ $id }}-name" class="form-control" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label for="new-{{ $id }}-phone" class="form-label">{{ __('Phone') }}</label>
                    <input id="new-{{ $id }}-phone" class="form-control" dir="ltr" autocomplete="off">
                </div>
                <div>
                    <label for="new-{{ $id }}-address" class="form-label">{{ __('Address') }}</label>
                    <input id="new-{{ $id }}-address" class="form-control" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    {{ __('Cancel') }}
                </button>
                <button type="button" class="btn btn-primary" id="new-{{ $id }}-save">
                    {{ __('Save and choose') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            const modalEl = document.getElementById('new-{{ $id }}-modal');
            const select = document.getElementById(@json($selectId));

            if (! modalEl || ! select) return;

            const save = document.getElementById('new-{{ $id }}-save');
            const error = document.getElementById('new-{{ $id }}-error');
            const fields = ['name', 'phone', 'address'].reduce((all, f) => {
                all[f] = document.getElementById(`new-{{ $id }}-${f}`);
                return all;
            }, {});

            save.addEventListener('click', async () => {
                error.classList.add('d-none');
                save.disabled = true;

                try {
                    const response = await fetch(@json(route($storeRoute)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({
                            name: fields.name.value,
                            phone: fields.phone.value,
                            address: fields.address.value,
                        }),
                    });

                    const body = await response.json();

                    if (! response.ok) {
                        error.textContent = Object.values(body.errors ?? {}).flat().join(' ')
                            || body.message
                            || @json(__('That could not be saved.'));
                        error.classList.remove('d-none');

                        return;
                    }

                    // Added to the list and chosen, so the cart carries on
                    // exactly where it was.
                    const option = new Option(body.name, body.id, true, true);
                    select.add(option);
                    select.dispatchEvent(new Event('change', { bubbles: true }));

                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    Object.values(fields).forEach((f) => { f.value = ''; });
                } catch (e) {
                    error.textContent = e.message;
                    error.classList.remove('d-none');
                } finally {
                    save.disabled = false;
                }
            });
        })();
    </script>
@endpush
