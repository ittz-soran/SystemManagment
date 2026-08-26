{{--
    The box a barcode goes into.

    There are two of these on a cart screen, top and bottom, and they are the
    same box twice rather than two different ones: the same lookup, the same
    keys, the same results. With twenty-five lines in the cart the top one has
    scrolled off the screen, and scanning the twenty-sixth thing should not mean
    scrolling back up to find where to put it. So the second one sits under the
    last line added, and after every add the page goes there.

    Expects: $suffix ('' for the top one, '-bottom' for the one under the cart).
--}}
<div class="card-body">
    <label for="product-search{{ $suffix }}" class="form-label small">
        {{ __('Scan a barcode, or search by name or SKU') }}
    </label>
    <input id="product-search{{ $suffix }}" type="text" class="form-control form-control-lg"
           autocomplete="off" data-cart-search
           @if($suffix === '') autofocus @endif
           placeholder="{{ __('Scan or type…') }}">
    <div id="search-results{{ $suffix }}" class="list-group mt-2 d-none"></div>
    <div class="form-text">
        {{ __('Enter adds · ↑ ↓ move · Esc clears · F2 saves') }}
    </div>
</div>
