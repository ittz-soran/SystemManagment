{{--
    A money box that knows which currency it is taking — Section 2b.

    Without a lens this renders exactly the input it replaced: whole base-
    currency units, `step="1"`, nothing new on the page. With one, it shows the
    figure in that currency, takes that currency's decimals, and names the
    currency beside itself so nobody types dinars into a dollar box.

    ⚠️ It also posts what it SHOWED, in a hidden field. That is the
    untouched-field rule — see App\Support\MoneyInput for the six dinars it
    exists to stop. Without it, opening a record and saving it unchanged
    rewrites every money field on the form by a rounding.
--}}
@props([
    'name',
    'value' => null,
    'lens' => null,
    'id' => null,
    'min' => null,
])

@php
    use App\Support\Money;
    use App\Support\MoneyInput;

    $id ??= $name;
    $shownField = MoneyInput::shownField($name);

    /*
     * What the field is rendered holding. `old()` is already in the typed
     * currency — nothing normalises the request, precisely so this is true
     * after a refused save.
     *
     * ⚠️ `plain()`, never `format()`. A thousands separator makes
     * `<input type="number">` reject the value and render EMPTY, with no error
     * anywhere: every box holding a thousand or more was blank.
     */
    $shown = $value === null ? '' : Money::plain((int) $value, $lens);
    $display = old($name, $shown);

    // The floor, said in the currency the box is taking.
    $least = $min === null
        ? null
        : ($lens === null ? (string) $min : Money::plain((int) $min, $lens));
@endphp

<div class="input-group @error($name) has-validation @enderror">
    <input id="{{ $id }}"
           type="number"
           step="{{ Money::step($lens) }}"
           @if($least !== null) min="{{ $least }}" @endif
           name="{{ $name }}"
           value="{{ $display }}"
           dir="ltr"
           {{ $attributes->merge(['class' => 'form-control text-end'.($errors->has($name) ? ' is-invalid' : '')]) }}>

    {{-- Always named, lens or not: a box that says IQD today must go on saying
         it, and a box taking dollars must say so loudest of all. --}}
    <span class="input-group-text app-code">{{ $lens?->mark() ?? __('IQD') }}</span>

    @if($lens)
        {{-- ⚠️ What this box was drawn with. If it comes back the same, the
             field was never edited and the stored figure is kept untouched. --}}
        <input type="hidden" name="{{ $shownField }}" value="{{ $shown }}">
    @endif

    @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
