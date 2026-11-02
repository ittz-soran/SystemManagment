{{--
    The add box — PROJECT_DOC Section 9, the sale/purchase screen. Soran,
    2026-11-02: "Every product before add to cart show cacullator to confirm
    price or change then confirm."

    One per page, opened by the page's own script through window.cartAddBox:
    open({ name, sku, note, price, places, mark }) gives back a promise of
    { price, changed, quantity }, or null when it was put back. The page
    decides what a price means — dinars, or dollars at the receipt's rate — and
    applies the untouched-field rule (Section 2b): `changed` false means the
    product goes in at its own price, exactly as before.

    When it opens at all is the setting cart_add_box: always, picked, never.

    Outside the cart's <form>: nothing in here is posted.
--}}
<div class="modal fade" id="cart-add-box" tabindex="-1" aria-labelledby="cart-add-box-title" aria-hidden="true"
     data-mode="{{ setting('cart_add_box', 'always') }}" data-base-mark="{{ App\Support\Money::base()->mark() }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div class="me-auto min-w-0">
                    <h6 class="modal-title text-truncate" id="cart-add-box-title" dir="auto"></h6>
                    <div class="small text-secondary" id="cart-add-box-note" dir="auto"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-7">
                        <label for="cart-add-price" class="form-label small">{{ __('Price') }}</label>
                        <div class="input-group">
                            {{-- Text, not number: it takes a sum — 25 × 1460. --}}
                            <input id="cart-add-price" type="text" inputmode="decimal" autocomplete="off"
                                   class="form-control form-control-lg text-end" dir="ltr" data-english-digits>
                            <span class="input-group-text" id="cart-add-mark"></span>
                        </div>
                    </div>
                    <div class="col-5">
                        <label for="cart-add-qty" class="form-label small">{{ __('Quantity') }}</label>
                        <input id="cart-add-qty" type="text" inputmode="numeric" autocomplete="off"
                               class="form-control form-control-lg text-end" dir="ltr" data-english-digits>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-baseline mt-2">
                    <span class="text-secondary small">{{ __('Line total') }}</span>
                    <output id="cart-add-total" class="fw-semibold fs-5" dir="ltr" data-cart-add-total>0</output>
                </div>
                <div class="small text-danger d-none" id="cart-add-error" role="alert"></div>

                <div class="number-pad-grid mt-3" id="cart-add-keys">
                    <button type="button" class="btn btn-outline-secondary" data-key="clear">{{ __('C') }}</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="back"
                            aria-label="{{ __('Backspace') }}"><i class="bi bi-backspace"></i></button>
                    <button type="button" class="btn btn-outline-primary" data-key="÷" aria-label="{{ __('Divide') }}">÷</button>
                    <button type="button" class="btn btn-outline-primary" data-key="×" aria-label="{{ __('Multiply') }}">×</button>

                    <button type="button" class="btn btn-outline-secondary" data-key="7">7</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="8">8</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="9">9</button>
                    <button type="button" class="btn btn-outline-primary" data-key="−" aria-label="{{ __('Subtract') }}">−</button>

                    <button type="button" class="btn btn-outline-secondary" data-key="4">4</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="5">5</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="6">6</button>
                    <button type="button" class="btn btn-outline-primary" data-key="+" aria-label="{{ __('Add') }}">+</button>

                    <button type="button" class="btn btn-outline-secondary" data-key="1">1</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="2">2</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="3">3</button>
                    <button type="button" class="btn btn-primary number-pad-equals" data-key="="
                            aria-label="{{ __('Equals') }}">=</button>

                    <button type="button" class="btn btn-outline-secondary" data-key="00">00</button>
                    <button type="button" class="btn btn-outline-secondary" data-key="0">0</button>
                    <button type="button" class="btn btn-outline-secondary" data-key=".">.</button>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary flex-fill" id="cart-add-ok">
                    <i class="bi bi-plus-lg me-1"></i>{{ __('Add to cart') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        window.cartAddBox = (() => {
            const box = document.getElementById('cart-add-box');

            if (! box) return null;

            const price = document.getElementById('cart-add-price');
            const quantity = document.getElementById('cart-add-qty');
            const total = document.getElementById('cart-add-total');
            const error = document.getElementById('cart-add-error');
            const words = {
                price: @json(__('That price is not a number. Type a figure, or a sum such as 25 × 1460.')),
                quantity: @json(__('The quantity must be a whole number, 1 or more.')),
                scan: @json(__('That was a barcode scanned into the box, not a price. Press Enter to add this product first, then scan the next one.')),
            };

            let answer = null;
            // Still fading in: Bootstrap ignores hide() until it has finished.
            let opening = false;
            let original = 0;
            let places = 0;
            let mark = '';
            let target = price;

            /**
             * A figure, or a sum of them: 25 × 1460, 15000 − 500, 36000 ÷ 3.
             * × and ÷ before + and −, as on paper. Read here rather than handed
             * to eval, so a box can never run anything typed into it. Null when
             * it is not a sum this can read.
             */
            function evaluate(text) {
                const source = String(text)
                    .replace(/[×xX*]/g, '*').replace(/÷/g, '/').replace(/[−–]/g, '-')
                    .replace(/,/g, '').replace(/\s+/g, '');

                if (source === '') return null;

                const tokens = source.match(/\d+(?:\.\d+)?|\.\d+|[+\-*/]/g);

                if (! tokens || tokens.join('') !== source) return null;

                let at = 0;
                const number = () => (/^[\d.]/.test(tokens[at] ?? '') ? Number(tokens[at++]) : NaN);

                let sum = 0;
                let sign = 1;
                let term = number();

                if (Number.isNaN(term)) return null;

                while (at < tokens.length) {
                    const operator = tokens[at++];
                    const next = number();

                    if (Number.isNaN(next)) return null;

                    if (operator === '*') {
                        term *= next;
                    } else if (operator === '/') {
                        if (next === 0) return null;
                        term /= next;
                    } else {
                        sum += sign * term;
                        sign = operator === '+' ? 1 : -1;
                        term = next;
                    }
                }

                sum += sign * term;

                return Number.isFinite(sum) ? sum : null;
            }

            const round = (value) => Number(value.toFixed(places));
            const written = (value) => value.toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places });
            const isSum = (text) => /[+\-×÷*/xX−]/.test(String(text).replace(/^\s*-/, ''));

            function say(message) {
                error.textContent = message ?? '';
                error.classList.toggle('d-none', ! message);
            }

            function update() {
                const each = evaluate(price.value);
                const count = Number(quantity.value);

                total.textContent = each !== null && Number.isInteger(count) && count > 0
                    ? `${written(round(each) * count)} ${mark}`
                    : '—';
            }

            /** A sum in the price box becomes its answer, and stays to be looked at. */
            function settle() {
                const each = evaluate(price.value);

                if (each === null) {
                    say(words.price);

                    return false;
                }

                price.value = String(round(each));
                say(null);
                update();

                return true;
            }

            function confirm() {
                if (isSum(price.value) && ! settle()) return;

                const each = evaluate(price.value);
                const count = Number(quantity.value);

                if (each === null || each < 0) {
                    say(words.price);
                    price.focus();

                    return;
                }

                if (! Number.isInteger(count) || count < 1) {
                    say(words.quantity);
                    quantity.focus();

                    return;
                }

                const given = answer;
                answer = null;
                close();

                given?.({ price: round(each), changed: round(each) !== round(original), quantity: count });
            }

            /*
             * ⚠️ Bootstrap ignores hide() while the box is still fading in, and a
             * scanner's Enter can land inside those few hundred milliseconds:
             * the product went into the cart and the box stayed open over the
             * page (found by driving it). A close that comes early waits for
             * the box to finish opening, then closes it.
             */
            function close() {
                const modal = bootstrap.Modal.getOrCreateInstance(box);

                if (opening) {
                    box.addEventListener('shown.bs.modal', () => modal.hide(), { once: true });
                } else {
                    modal.hide();
                }
            }

            /*
             * ⚠️ A scanner is a keyboard. With the box open for every scan, the
             * NEXT scan arrives as a burst of digits and an Enter in whichever
             * box has the caret — and a price of 6291041500213 is one Enter
             * away from the cart. A person types a key every tenth of a second
             * or slower; a scanner, several in a hundredth. A burst like that is
             * put back, never read as a price.
             */
            function watchForScans(field) {
                let burst = { count: 0, last: 0, before: '' };

                field.addEventListener('keydown', (event) => {
                    const now = performance.now();

                    if (event.key.length === 1 && ! event.ctrlKey && ! event.metaKey) {
                        if (now - burst.last > 35) burst = { count: 0, last: now, before: field.value };
                        burst.count += 1;
                        burst.last = now;

                        return;
                    }

                    if (event.key !== 'Enter') return;

                    event.preventDefault();

                    if (burst.count >= 6 && now - burst.last < 80) {
                        field.value = burst.before;
                        burst = { count: 0, last: 0, before: '' };
                        say(words.scan);
                        update();

                        return;
                    }

                    // Enter on a sum shows its answer first; Enter again adds.
                    if (field === price && isSum(price.value)) {
                        settle();

                        return;
                    }

                    confirm();
                });
            }

            [price, quantity].forEach((field) => {
                field.addEventListener('focus', () => { target = field; });
                field.addEventListener('input', () => { say(null); update(); });
                watchForScans(field);
            });

            document.getElementById('cart-add-keys').addEventListener('click', (event) => {
                const key = event.target.closest('[data-key]')?.dataset.key;

                if (! key) return;

                if (key === '=') {
                    settle();
                } else if (key === 'clear') {
                    target.value = '';
                } else if (key === 'back') {
                    target.value = target.value.slice(0, -1);
                } else {
                    // The first key on a figure nobody has touched replaces it,
                    // as on any calculator.
                    const fresh = target.selectionStart === 0 && target.selectionEnd === target.value.length && target.value !== '';
                    target.value = fresh && /\d/.test(key) ? key : target.value + key;
                }

                target.focus();
                target.setSelectionRange(target.value.length, target.value.length);
                say(null);
                update();
            });

            document.getElementById('cart-add-ok').addEventListener('click', confirm);

            box.addEventListener('shown.bs.modal', () => {
                opening = false;

                // Already answered while it was opening: nothing to type into.
                if (answer === null) return;

                price.focus();
                price.select();
            });

            // Put back: the page hears null, and nothing goes in.
            box.addEventListener('hidden.bs.modal', () => {
                const given = answer;
                answer = null;
                given?.(null);
            });

            return {
                /** When the box opens, from the setting: 'always', 'picked' or 'never'. */
                mode: box.dataset.mode || 'always',

                open(product) {
                    places = Number(product.places ?? 0);
                    mark = product.mark ?? box.dataset.baseMark;
                    original = Number(product.price ?? 0);

                    document.getElementById('cart-add-box-title').textContent = product.name ?? '';
                    document.getElementById('cart-add-box-note').textContent = product.note ?? '';
                    document.getElementById('cart-add-mark').textContent = mark;

                    price.value = String(round(original));
                    quantity.value = String(product.quantity ?? 1);
                    target = price;
                    say(null);
                    update();

                    return new Promise((resolve) => {
                        answer = resolve;
                        opening = true;
                        bootstrap.Modal.getOrCreateInstance(box).show();
                    });
                },
            };
        })();
    </script>
@endpush
