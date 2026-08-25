@extends('layouts.app')

@section('title', __('Buy a second-hand item'))
@section('subheading', __('The item and the purchase are recorded together'))

@section('back')
    <x-back-link :to="route('second-hand.index')" :label="__('Second-hand')" remember="second-hand" permission="products.view" />
@endsection

@section('content')
    <form action="{{ route('second-hand.store') }}" method="POST" data-guard-submit>
        @csrf

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header">{{ __('The item') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">{{ __('What is it') }}</label>
                            <input id="name" name="name" value="{{ old('name') }}" required autofocus
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="{{ __('Xbox Series S 512GB') }}">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="condition_note" class="form-label">{{ __('Condition') }}</label>
                            <input id="condition_note" name="condition_note" value="{{ old('condition_note') }}"
                                   class="form-control @error('condition_note') is-invalid @enderror"
                                   placeholder="{{ __('One controller, no box, small scratch on the lid') }}">
                            <div class="form-text">
                                {{ __('Half of what the price is based on. Worth writing down while it is in your hand.') }}
                            </div>
                            @error('condition_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="category_id" class="form-label">{{ __('Category') }}</label>
                                <select id="category_id" name="category_id" class="form-select">
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}"
                                            @selected(old('category_id', $defaultCategory?->id) == $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="bought_at" class="form-label">{{ __('Date') }}</label>
                                <input id="bought_at" type="date" name="bought_at" dir="ltr" required
                                       value="{{ old('bought_at', now()->toDateString()) }}"
                                       class="form-control @error('bought_at') is-invalid @enderror">
                                @error('bought_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ __('Who you bought it from') }}</div>
                    <div class="card-body">
                        {{-- Not a supplier. Somebody who walked in once with an
                             old console, whose own screen is under Second-hand
                             and who never appears on the supplier list.

                             Nothing is matched behind the shopkeeper's back:
                             this searches the people already bought from, and
                             picking one is what makes it that person. Typing a
                             name and leaving the list alone makes a new one. --}}
                        <div class="mb-3 position-relative">
                            <label for="seller-search" class="form-label">{{ __('Bought from') }}</label>
                            <input id="seller-search" type="text" class="form-control"
                                   placeholder="{{ __('Type a name or number to find someone you have bought from…') }}"
                                   autocomplete="off">
                            <div id="seller-results" class="list-group position-absolute w-100 shadow-sm d-none"
                                 style="z-index: 5"></div>
                        </div>

                        <div id="seller-chosen" class="alert alert-secondary py-2 d-none">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-person-check"></i>
                                <span class="fw-medium" id="seller-chosen-name"></span>
                                <span class="small text-secondary" id="seller-chosen-owed"></span>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none ms-auto"
                                        id="seller-clear">{{ __('Someone else') }}</button>
                            </div>
                        </div>

                        <input type="hidden" name="seller_id" id="seller_id" value="{{ old('seller_id') }}">

                        <div class="row g-3" id="seller-new">
                            <div class="col-sm-6">
                                <label for="seller_name" class="form-label">{{ __('Name') }}</label>
                                <input id="seller_name" name="seller_name" value="{{ old('seller_name') }}" required
                                       autocomplete="off"
                                       class="form-control @error('seller_name') is-invalid @enderror">
                                @error('seller_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-6">
                                <label for="seller_phone" class="form-label">{{ __('Phone') }}</label>
                                <input id="seller_phone" name="seller_phone" value="{{ old('seller_phone') }}" dir="ltr"
                                       autocomplete="off"
                                       class="form-control @error('seller_phone') is-invalid @enderror">
                                <div class="form-text">
                                    {{ __('A number makes them easy to find next time. It is not required.') }}
                                </div>
                                @error('seller_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">{{ __('The money') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="cost" class="form-label">{{ __('Price agreed') }}</label>
                            <div class="input-group">
                                <input id="cost" type="number" step="1" min="0" name="cost" dir="ltr" required
                                       value="{{ old('cost') }}" data-numpad
                                       class="form-control text-end @error('cost') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('This is the cost of this one item, and the cost its profit is measured against.') }}
                            </div>
                            @error('cost')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="amount_paid" class="form-label">{{ __('Paid now') }}</label>
                            <div class="input-group">
                                <input id="amount_paid" type="number" step="1" min="0" name="amount_paid" dir="ltr"
                                       value="{{ old('amount_paid', 0) }}" data-numpad
                                       class="form-control text-end @error('amount_paid') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('Anything left over stays as what you owe them, and can be paid later.') }}
                            </div>
                            @error('amount_paid')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="payment_method" class="form-label">{{ __('Method') }}</label>
                            <select id="payment_method" name="payment_method" class="form-select">
                                <option value="cash" @selected(old('payment_method') === 'cash')>{{ __('Cash') }}</option>
                                <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('Bank') }}</option>
                                <option value="transfer" @selected(old('payment_method') === 'transfer')>{{ __('Transfer') }}</option>
                            </select>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label for="sale_price" class="form-label">{{ __('Asking price') }}</label>
                            <div class="input-group">
                                <input id="sale_price" type="number" step="1" min="0" name="sale_price" dir="ltr" required
                                       value="{{ old('sale_price') }}" data-numpad
                                       class="form-control text-end @error('sale_price') is-invalid @enderror">
                                <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                            </div>
                            <div class="form-text">
                                {{ __('What the cart will suggest. You can still change it when it sells.') }}
                            </div>
                            @error('sale_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <button class="btn btn-primary w-100" data-submitting-text="{{ __('Saving…') }}">
                            <i class="bi bi-bag-check me-1"></i>{{ __('Buy it') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        /**
         * The people already bought from, offered as the shopkeeper types.
         *
         * It offers; it never decides. Picking somebody sends their id with the
         * form and that is who the buy belongs to. Ignoring the list and typing
         * a name makes a new person — which is what "I bought this off someone
         * new today" should do, and what the old phone matching would not let
         * it do.
         */
        (() => {
            const search = document.getElementById('seller-search');

            if (! search) return;

            const results = document.getElementById('seller-results');
            const chosen = document.getElementById('seller-chosen');
            const chosenName = document.getElementById('seller-chosen-name');
            const chosenOwed = document.getElementById('seller-chosen-owed');
            const idField = document.getElementById('seller_id');
            const nameField = document.getElementById('seller_name');
            const phoneField = document.getElementById('seller_phone');
            const newBlock = document.getElementById('seller-new');
            const url = @json(route('second-hand.sellers.search'));

            let timer = null;

            const hide = () => results.classList.add('d-none');

            function forget() {
                idField.value = '';
                chosen.classList.add('d-none');
                newBlock.classList.remove('d-none');
                nameField.required = true;
                search.value = '';
                hide();
                nameField.focus();
            }

            function choose(seller) {
                idField.value = seller.id;
                chosenName.textContent = seller.phone ? `${seller.name} · ${seller.phone}` : seller.name;
                chosenOwed.textContent = seller.balance_label ?? '';
                chosen.classList.remove('d-none');

                // The new-person boxes are filled and put away rather than
                // cleared: the server still wants a name, and if the reader
                // changes their mind the values are already there.
                nameField.value = seller.name;
                phoneField.value = seller.phone ?? '';
                newBlock.classList.add('d-none');

                search.value = '';
                hide();
            }

            document.getElementById('seller-clear').addEventListener('click', forget);

            search.addEventListener('input', () => {
                clearTimeout(timer);

                const term = search.value.trim();

                if (term === '') return hide();

                timer = setTimeout(async () => {
                    const response = await fetch(`${url}?q=${encodeURIComponent(term)}`, {
                        headers: { Accept: 'application/json' },
                    });

                    if (! response.ok) return hide();

                    const sellers = await response.json();

                    results.innerHTML = '';

                    if (sellers.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'list-group-item small text-secondary';
                        empty.textContent = @json(__('Nobody yet — type the name below to add them.'));
                        results.appendChild(empty);
                        results.classList.remove('d-none');

                        return;
                    }

                    sellers.forEach((seller) => {
                        const row = document.createElement('button');
                        row.type = 'button';
                        row.className = 'list-group-item list-group-item-action';
                        row.innerHTML =
                            `<span class="fw-medium"></span>` +
                            `<span class="small text-secondary ms-2" dir="ltr"></span>` +
                            `<span class="small text-danger ms-2"></span>`;
                        row.children[0].textContent = seller.name;
                        row.children[1].textContent = seller.phone ?? '';
                        row.children[2].textContent = seller.balance_label ?? '';
                        row.addEventListener('click', () => choose(seller));
                        results.appendChild(row);
                    });

                    results.classList.remove('d-none');
                }, 250);
            });

            document.addEventListener('click', (event) => {
                if (! results.contains(event.target) && event.target !== search) hide();
            });

            // Coming back from a failed save with somebody already picked.
            @if(old('seller_id'))
                chosenName.textContent = @json(old('seller_name'));
                chosen.classList.remove('d-none');
                newBlock.classList.add('d-none');
            @endif
        })();
    </script>
@endpush
