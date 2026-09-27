@php $isNew = ! $product->exists; @endphp

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">{{ __('Details') }}</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('Name') }}</label>
                    {{-- Section 9, the fourth help: the browser's own
                         spellchecker, switched back on for this one field.
                         `<body>` turns it off everywhere else. It will
                         underline every brand the shop sells and cannot be
                         taught otherwise — which is what the three helps below
                         the box are for. --}}
                    <input id="name" name="name" value="{{ old('name', $product->name) }}" spellcheck="true"
                           class="form-control @error('name') is-invalid @enderror" required autofocus>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    {{-- Section 9 — "Help with the name of a product". Filled
                         in by the script at the foot of this form, and empty
                         until the server has something to say. It advises: it
                         cannot refuse a save, and nothing here changes what is
                         typed unless the person presses the button. --}}
                    <div id="name-advice" class="mt-2"
                         data-url="{{ route('products.name-advice') }}"
                         @if($product->exists) data-ignore="{{ $product->id }}" @endif></div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="sku" class="form-label">{{ __('SKU') }}</label>
                        <input id="sku" name="sku" value="{{ old('sku', $product->sku) }}" dir="ltr" data-english-digits
                               class="form-control @error('sku') is-invalid @enderror"
                               placeholder="{{ __('Leave blank to generate :prefix…', ['prefix' => setting('sku_prefix', 'SS')]) }}">
                        {{-- Section 4: type the manufacturer code, or leave blank
                             and the system generates SS + the next number. --}}
                        <div class="form-text">{{ __('Type the manufacturer code, or leave blank to generate one.') }}</div>
                        @error('sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="barcode" class="form-label">{{ __('Barcode') }}</label>
                        <input id="barcode" name="barcode" data-rescan data-english-digits value="{{ old('barcode', $product->barcode) }}" dir="ltr"
                               class="form-control @error('barcode') is-invalid @enderror"
                               placeholder="{{ __('Scan, type, or leave blank') }}">
                        <div class="form-text">{{ __('Left blank, an EAN-13 is generated so the product scans at the till.') }}</div>
                        @error('barcode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="category_id" class="form-label">{{ __('Category') }}</label>
                        <select id="category_id" name="category_id"
                                class="form-select @error('category_id') is-invalid @enderror" required>
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}"
                                        @selected(old('category_id', $product->category_id) == $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="unit" class="form-label">{{ __('Unit') }}</label>
                        {{-- Section 8c: the shop's own list, edited in Settings.
                             A product measured in a unit since taken off that
                             list still finds it here — see Units::forSelect, and
                             the silent re-measuring it exists to prevent. --}}
                        <select id="unit" name="unit"
                                class="form-select @error('unit') is-invalid @enderror" required>
                            @foreach(App\Support\Units::forSelect($product->unit ?? null) as $unit)
                                <option value="{{ $unit }}"
                                        @selected(old('unit', $product->unit ?? App\Support\Units::default()) === $unit)>
                                    {{ $unit }}
                                </option>
                            @endforeach
                        </select>
                        @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">{{ __('Prices') }}</div>
            <div class="card-body">
                {{-- Section 4: these are only default suggestions for the cart. --}}
                <p class="small text-secondary">
                    {{ __('Suggestions for the cart only. The real cost of each unit comes from its purchase batch.') }}
                </p>

                {{-- A reader whose cost is masked gets the mask here too, and it
                     is not a field: showing them the stored number would undo
                     the setting, and showing them a marked-up one would save it
                     back as fact the next time somebody pressed save. Nothing
                     is posted, and the controller keeps what is already there. --}}
                <div class="mb-3">
                    <label for="purchase_price" class="form-label">{{ __('Purchase price') }}</label>
                    @if(auth()->user()->seesRealCost())
                        <x-money-input name="purchase_price" :lens="$lens" :min="0"
                                       :value="$product->purchase_price ?? 0" required
                                       :data-numpad="__('Purchase price')" />
                    @else
                        <div class="input-group">
                            <input id="purchase_price" type="text" class="form-control text-end" dir="ltr"
                                   value="{{ hidden_money() }}" disabled>
                            <span class="input-group-text app-code">{{ $lens?->mark() ?? __('IQD') }}</span>
                        </div>
                        <div class="form-text">{{ __('Set by somebody who can see what things cost.') }}</div>
                    @endif
                </div>

                <div class="mb-3">
                    <label for="sale_price" class="form-label">{{ __('Sale price') }}</label>
                    <x-money-input name="sale_price" :lens="$lens" :min="0"
                                   :value="$product->sale_price ?? 0" required
                                   :data-numpad="__('Sale price')" />
                </div>

                <div class="mb-3">
                    <label for="reorder_level" class="form-label">{{ __('Reorder level') }}</label>
                    <input id="reorder_level" type="number" step="1" min="0" name="reorder_level"
                           value="{{ old('reorder_level', $product->reorder_level) }}" dir="ltr"
                           class="form-control text-end @error('reorder_level') is-invalid @enderror"
                           placeholder="{{ setting('low_stock_threshold', 0) }}">
                    <div class="form-text">
                        {{ __('Blank uses the shop default of :count.', ['count' => setting('low_stock_threshold', 0)]) }}
                    </div>
                    @error('reorder_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $product->is_active ?? true))>
                    <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                </div>
            </div>
        </div>

        @if($isNew)
            {{-- Section 5: products already in the shop need a starting batch —
                 quantity plus its cost — or FIFO has no first layer. --}}
            <div class="card">
                <div class="card-header">{{ __('Opening stock') }}</div>
                <div class="card-body">
                    <p class="small text-secondary">
                        {{ auth()->user()->seesRealCost()
                            ? __('If you already hold this product, enter how many and what each one cost you. That becomes its first FIFO layer.')
                            : __('Opening stock needs a cost for every unit, so somebody who can see what things cost has to enter it. Add it here later, or use a stock adjustment.') }}
                    </p>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="opening_quantity" class="form-label">{{ __('Quantity') }}</label>
                            {{-- The unit chosen above, echoed here so the opening
                                 count is never a bare number. It follows the
                                 select, since both live on this one form. --}}
                            <div class="input-group">
                                <input id="opening_quantity" type="number" step="1" min="0" name="opening_quantity" data-numpad="{{ __('Quantity') }}"
                                       value="{{ old('opening_quantity') }}" dir="ltr"
                                       class="form-control text-end @error('opening_quantity') is-invalid @enderror">
                                <span id="opening_unit" class="input-group-text"></span>
                            </div>
                            @error('opening_quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="opening_unit_cost" class="form-label">{{ __('Cost each') }}</label>
                            @if(auth()->user()->seesRealCost())
                                {{-- No value: opening stock is only set when a
                                     product is created, so there is never a
                                     stored figure for the box to start from. --}}
                                <x-money-input name="opening_unit_cost" :lens="$lens" :min="0"
                                               :data-numpad="__('Cost each')" />
                            @else
                                {{-- FIFO needs a cost for every unit, and this
                                     reader has none to give. --}}
                                <input id="opening_unit_cost" type="text" class="form-control text-end"
                                       dir="ltr" value="{{ hidden_money() }}" disabled>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Section 9b: a product is saved on purpose, never by accident.

     The barcode field is read by a scanner, and a scanner types the code and
     then presses Enter. Scan twice and the second Enter used to submit the
     form; so did Enter pressed anywhere else, on the reorder level or any other
     box. app.js turns every save button in the shop into one that has to be
     held for two seconds, which takes Enter out of the picture entirely. --}}
<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>{{ __('Save product') }}
    </button>
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
</div>

@push('scripts')
    <script>
        // The opening-stock box is counted in whatever unit the product is set
        // to, and that select sits on this same form — so the suffix follows it.
        (() => {
            const unit = document.getElementById('unit');
            const echo = document.getElementById('opening_unit');

            if (! unit || ! echo) {
                return;
            }

            const sync = () => { echo.textContent = unit.value; };

            unit.addEventListener('change', sync);
            sync();
        })();

        /*
         * Section 9 — "Help with the name of a product".
         *
         * Two of the three helps live here, and both only advise: the look-alike
         * warning and the spellings the shop's own catalogue already uses. The
         * third — tidying the name — is done on the server when the form is
         * saved, so this script being blocked, slow or broken costs the shop
         * nothing but the advice.
         */
        (() => {
            const box = document.getElementById('name');
            const out = document.getElementById('name-advice');

            if (! box || ! out) {
                return;
            }

            /* ⚠️ The array is built first and handed over as ONE variable.
               Blade's json directive splits its own argument on commas and
               takes the second and third as json_encode's flags and depth, so
               an array literal written inline is silently truncated at its
               first comma and the page is left with broken JavaScript. */
            @php($say = [
                'certain' => __('You almost certainly already sell this'),
                'maybe' => __('Worth a look before you save'),
                'spelling' => __('You usually write it :word — it is in :count of your products.'),
                'use' => __('Use it'),
            ])
            const say = @json($say);

            let timer = null;
            let asked = '';

            const draw = (advice) => {
                out.innerHTML = '';

                const alikes = advice.look_alikes || [];

                if (alikes.length) {
                    const sure = alikes.some((hit) => hit.certain);
                    const card = document.createElement('div');
                    card.className = 'alert small py-2 px-3 mb-0 ' + (sure ? 'alert-warning' : 'alert-secondary');

                    const head = document.createElement('div');
                    head.className = 'fw-semibold';
                    head.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>';
                    head.append(sure ? say.certain : say.maybe);
                    card.append(head);

                    const list = document.createElement('ul');
                    list.className = 'list-unstyled mb-0 mt-1';

                    alikes.forEach((hit) => {
                        // A flex row with a gap rather than a margin on the
                        // code: under RTL the margin lands on the wrong side of
                        // the bidi run and the SKU touches the name.
                        const row = document.createElement('li');
                        row.className = 'd-flex flex-wrap gap-2';
                        const link = document.createElement('a');
                        link.href = hit.url;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.textContent = hit.name;
                        row.append(link);

                        if (hit.sku) {
                            const code = document.createElement('span');
                            code.className = 'text-secondary app-code';
                            code.textContent = hit.sku;
                            row.append(code);
                        }

                        list.append(row);
                    });

                    card.append(list);
                    out.append(card);
                }

                (advice.spellings || []).forEach((hint) => {
                    const card = document.createElement('div');
                    card.className = 'alert alert-info small py-2 px-3 mb-0 mt-2 d-flex flex-wrap align-items-center gap-2';

                    const text = document.createElement('span');
                    text.innerHTML = '<i class="bi bi-lightbulb me-1"></i>';
                    text.append(say.spelling
                        .replace(':word', '\u201C' + hint.suggested + '\u201D')
                        .replace(':count', hint.seen));
                    card.append(text);

                    const fix = document.createElement('button');
                    fix.type = 'button';
                    fix.className = 'btn btn-sm btn-outline-primary py-0 px-2';
                    fix.textContent = say.use;
                    // Only the word that was flagged, and only where it stands
                    // alone — so "Wirless" in "Wirless Mouse" is replaced and
                    // nothing inside a part number is touched.
                    fix.addEventListener('click', () => {
                        const word = hint.typed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        box.value = box.value.replace(new RegExp('\\b' + word + '\\b', 'giu'), hint.suggested);
                        box.dispatchEvent(new Event('input'));
                        box.focus();
                    });
                    card.append(fix);

                    out.append(card);
                });
            };

            const ask = () => {
                const name = box.value.trim();

                if (name.length < 4) {
                    out.innerHTML = '';
                    asked = '';

                    return;
                }

                if (name === asked) {
                    return;
                }

                asked = name;

                const url = new URL(out.dataset.url, window.location.origin);
                url.searchParams.set('name', name);

                if (out.dataset.ignore) {
                    url.searchParams.set('ignore', out.dataset.ignore);
                }

                fetch(url, { headers: { Accept: 'application/json' } })
                    .then((response) => (response.ok ? response.json() : null))
                    .then((advice) => {
                        // A slow answer to an old question must not overwrite a
                        // newer one, and a refusal simply says nothing.
                        if (advice && box.value.trim() === asked) {
                            draw(advice);
                        }
                    })
                    .catch(() => {});
            };

            box.addEventListener('input', () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(ask, 400);
            });

            // An edit screen starts with a name already in the box; a fresh one
            // does not, and `ask` returns on its own.
            ask();
        })();
    </script>
@endpush
