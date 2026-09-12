{{--
    Several things chosen from a list, as a control that looks like the others.

    **Soran marked "fix his input sizes" at this, 2026-09-12**, and measuring it
    showed the problem was not the size. It was `<select multiple size="1">`:
    a multiple select is a LIST BOX, and no browser will render one as a
    dropdown however small you ask for it. Chrome made it 40px tall beside 31px
    inputs; iOS Safari collapsed it to a box reading **"0 Items"**, which is the
    operating system's own wording and says nothing about categories at all.

    So this is the same choice offered as a real control: a dropdown of
    checkboxes. It says what is chosen in the shop's own words, it matches the
    height of the fields either side of it, and a checkbox is a far bigger thing
    to hit with a thumb than a row in a native list box.

    The name and values are unchanged — `categories[]` still arrives exactly as
    the controller already reads it. Nothing behind this screen knows the
    difference.

    ⚠️ Without JavaScript the checkboxes are still there, still inside the form,
    and still submit. What is lost is the summary on the button and the menu
    opening — Bootstrap's own dropdown handles both, and it is already on every
    page. This does not invent a widget; it arranges two that exist.
--}}
@props([
    'name',
    'label',
    'options' => [],      // [value => label]
    'selected' => [],
    'all' => null,        // what to say when nothing is picked
])

@php
    $id = 'pick-'.\Illuminate\Support\Str::slug($name);
    $selected = array_map('strval', (array) $selected);

    $chosen = array_values(array_filter(
        $options,
        fn ($value) => in_array((string) $value, $selected, true),
        ARRAY_FILTER_USE_KEY,
    ));

    // One name, two names, then a count. A button that lists nine categories is
    // a button the row cannot hold, and "9 chosen" is what a person would say.
    $summary = match (true) {
        $chosen === [] => $all ?? __('All'),
        count($chosen) <= 2 => implode(', ', $chosen),
        default => trans_choice(':count chosen', count($chosen), ['count' => count($chosen)]),
    };
@endphp

<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex
                   justify-content-between align-items-center app-picker"
            type="button" id="{{ $id }}" data-bs-toggle="dropdown" data-bs-auto-close="outside"
            aria-expanded="false">
        <span class="text-truncate">{{ $summary }}</span>
    </button>

    <div class="dropdown-menu p-2 app-picker-menu" aria-labelledby="{{ $id }}">
        @forelse($options as $value => $text)
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="{{ $id }}-{{ $value }}"
                       name="{{ $name }}" value="{{ $value }}"
                       @checked(in_array((string) $value, $selected, true))>
                <label class="form-check-label" for="{{ $id }}-{{ $value }}">{{ $text }}</label>
            </div>
        @empty
            <div class="small text-secondary px-1">{{ __('Nothing to choose from yet.') }}</div>
        @endforelse
    </div>
</div>
