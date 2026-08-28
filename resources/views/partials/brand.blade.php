@php
    // Section 8c: "brand values are emitted as CSS custom properties and read by
    // Bootstrap. Stylesheets are never regenerated at runtime."
    //
    // Two things make that harder than it sounds, and both were live bugs.
    //
    // 1. This block has to come AFTER the compiled stylesheet. Bootstrap sets
    //    --bs-body-font-family and --bs-primary in its own :root, so an override
    //    emitted earlier loses to it on source order and the setting appears to
    //    do nothing at all.
    //
    // 2. Bootstrap 5.3 compiles component colours from SCSS: .btn-primary
    //    carries a literal --bs-btn-bg: #0d6efd rather than var(--bs-primary).
    //    So the variable alone is not enough — the component variables have to
    //    be set too, which is what most of this file is.
    // Both colours go through brand_palette, which refuses anything that is not
    // a plain hex — so nothing arbitrary can be written into this stylesheet.
    $primary = brand_palette(setting('primary_color', '#0d6efd'));
    $secondary = brand_palette(setting('secondary_color', '#6c757d'), '#6c757d');

    // The font stack has to be emitted unescaped, because a family name is
    // quoted ('Noto Sans Arabic') and Blade would turn those quotes into &#039;
    // — which a <style> block does not decode, so the whole declaration becomes
    // invalid and the font silently falls back. Matching it against the vetted
    // list first is what makes printing it raw safe.
    // The secondary colour is applied to buttons and badges only, never to
    // Bootstrap's --bs-secondary-rgb. That variable is what .text-secondary
    // reads, and this interface uses that class for muted helper text on
    // practically every form — setting it turns every hint and caption the
    // brand colour, which is not what anyone choosing a "secondary colour" is
    // asking for.
    $fonts = \App\Http\Controllers\SettingController::FONTS;
    $stored = (string) setting('font_family');
    $font = array_key_exists($stored, $fonts) ? $stored : array_key_first($fonts);
@endphp

<style>
    :root,
    [data-bs-theme="light"],
    [data-bs-theme="dark"] {
        --bs-body-font-family: {!! $font !!};

        --bs-primary: {{ $primary['hex'] }};
        --bs-primary-rgb: {{ $primary['rgb'] }};
        --bs-primary-text-emphasis: {{ $primary['active'] }};
        --bs-primary-bg-subtle: {{ $primary['subtle'] }};
        --bs-primary-border-subtle: {{ $primary['subtle'] }};

        --bs-secondary: {{ $secondary['hex'] }};

        /* Links, and the focus ring that follows them. */
        --bs-link-color: {{ $primary['hex'] }};
        --bs-link-color-rgb: {{ $primary['rgb'] }};
        --bs-link-hover-color: {{ $primary['hover'] }};
        --bs-focus-ring-color: rgba({{ $primary['rgb'] }}, .25);
    }

    /* Buttons carry their own compiled colours, so each one is named. */
    .btn-primary {
        --bs-btn-color: {{ $primary['on'] }};
        --bs-btn-bg: {{ $primary['hex'] }};
        --bs-btn-border-color: {{ $primary['hex'] }};
        --bs-btn-hover-color: {{ $primary['on'] }};
        --bs-btn-hover-bg: {{ $primary['hover'] }};
        --bs-btn-hover-border-color: {{ $primary['hover'] }};
        --bs-btn-active-color: {{ $primary['on'] }};
        --bs-btn-active-bg: {{ $primary['active'] }};
        --bs-btn-active-border-color: {{ $primary['active'] }};
        --bs-btn-disabled-color: {{ $primary['on'] }};
        --bs-btn-disabled-bg: {{ $primary['hex'] }};
        --bs-btn-disabled-border-color: {{ $primary['hex'] }};
        --bs-btn-focus-shadow-rgb: {{ $primary['rgb'] }};
    }

    .btn-outline-primary {
        --bs-btn-color: {{ $primary['hex'] }};
        --bs-btn-border-color: {{ $primary['hex'] }};
        --bs-btn-hover-color: {{ $primary['on'] }};
        --bs-btn-hover-bg: {{ $primary['hex'] }};
        --bs-btn-hover-border-color: {{ $primary['hex'] }};
        --bs-btn-active-color: {{ $primary['on'] }};
        --bs-btn-active-bg: {{ $primary['hex'] }};
        --bs-btn-active-border-color: {{ $primary['hex'] }};
        --bs-btn-disabled-color: {{ $primary['hex'] }};
        --bs-btn-disabled-border-color: {{ $primary['hex'] }};
        --bs-btn-focus-shadow-rgb: {{ $primary['rgb'] }};
    }

    .btn-secondary {
        --bs-btn-color: {{ $secondary['on'] }};
        --bs-btn-bg: {{ $secondary['hex'] }};
        --bs-btn-border-color: {{ $secondary['hex'] }};
        --bs-btn-hover-color: {{ $secondary['on'] }};
        --bs-btn-hover-bg: {{ $secondary['hover'] }};
        --bs-btn-hover-border-color: {{ $secondary['hover'] }};
        --bs-btn-active-color: {{ $secondary['on'] }};
        --bs-btn-active-bg: {{ $secondary['active'] }};
        --bs-btn-active-border-color: {{ $secondary['active'] }};
        --bs-btn-focus-shadow-rgb: {{ $secondary['rgb'] }};
    }

    .btn-outline-secondary {
        --bs-btn-color: {{ $secondary['hex'] }};
        --bs-btn-border-color: {{ $secondary['hex'] }};
        --bs-btn-hover-color: {{ $secondary['on'] }};
        --bs-btn-hover-bg: {{ $secondary['hex'] }};
        --bs-btn-hover-border-color: {{ $secondary['hex'] }};
        --bs-btn-active-color: {{ $secondary['on'] }};
        --bs-btn-active-bg: {{ $secondary['hex'] }};
        --bs-btn-active-border-color: {{ $secondary['hex'] }};
        --bs-btn-focus-shadow-rgb: {{ $secondary['rgb'] }};
    }

    /* A primary badge already takes its background from the variable above;
       only its hardcoded white text needs replacing. A secondary badge needs
       both, since the secondary colour stops at buttons and badges. */
    .text-bg-primary { color: {{ $primary['on'] }} !important; }

    .text-bg-secondary {
        background-color: {{ $secondary['hex'] }} !important;
        color: {{ $secondary['on'] }} !important;
    }

    /* Form focus, checkboxes and radios are all compiled against $primary. */
    .form-control:focus,
    .form-select:focus,
    .form-check-input:focus {
        border-color: {{ $primary['hover'] }};
        box-shadow: 0 0 0 .25rem rgba({{ $primary['rgb'] }}, .25);
    }

    .form-check-input:checked {
        background-color: {{ $primary['hex'] }};
        border-color: {{ $primary['hex'] }};
    }

    .form-check-input:focus { border-color: {{ $primary['hover'] }}; }

    .pagination {
        --bs-pagination-color: {{ $primary['hex'] }};
        --bs-pagination-hover-color: {{ $primary['hover'] }};
        --bs-pagination-focus-color: {{ $primary['hover'] }};
        --bs-pagination-focus-box-shadow: 0 0 0 .25rem rgba({{ $primary['rgb'] }}, .25);
        --bs-pagination-active-bg: {{ $primary['hex'] }};
        --bs-pagination-active-border-color: {{ $primary['hex'] }};
        --bs-pagination-active-color: {{ $primary['on'] }};
    }

    .dropdown-menu {
        --bs-dropdown-link-active-bg: {{ $primary['hex'] }};
        --bs-dropdown-link-active-color: {{ $primary['on'] }};
    }

    .list-group {
        --bs-list-group-active-bg: {{ $primary['hex'] }};
        --bs-list-group-active-border-color: {{ $primary['hex'] }};
        --bs-list-group-active-color: {{ $primary['on'] }};
        --bs-list-group-action-active-bg: {{ $primary['subtle'] }};
    }

    .nav-pills {
        --bs-nav-pills-link-active-bg: {{ $primary['hex'] }};
        --bs-nav-pills-link-active-color: {{ $primary['on'] }};
    }

    .progress { --bs-progress-bar-bg: {{ $primary['hex'] }}; }

    /* The sidebar's own active row, which reads the variable directly. */
    .app-sidebar .nav-link.active { color: {{ $primary['on'] }}; }

    /*
     * Colour used as colour, rather than as a background with text on top.
     *
     * Everything above sets what a filled button looks like, where the brand
     * colour is behind the words. An outline button, an icon and a link are the
     * other way round: the brand colour IS the words, and then it has to carry
     * the contrast by itself against the page. The default grey does not —
     * #6c757d on Bootstrap's dark page is 2.8:1, which is what "New adjustment"
     * looked like on a dark screen — and neither does a pale brand colour on a
     * white one. brand_palette walks each colour away from the page until it
     * clears 4.5:1, and those are the two variants used here.
     */
    .btn-outline-primary {
        --bs-btn-color: {{ $primary['on_light'] }};
        --bs-btn-border-color: {{ $primary['on_light'] }};
    }

    .btn-outline-secondary {
        --bs-btn-color: {{ $secondary['on_light'] }};
        --bs-btn-border-color: {{ $secondary['on_light'] }};
    }

    /* Written flat rather than nested: nesting is native CSS now, but this
       block is served to whatever browser the shop has, and a counter machine
       is not where a new one gets installed. */
    [data-bs-theme="dark"] {
        --bs-link-color: {{ $primary['on_dark'] }};
        --bs-link-color-rgb: {{ $primary['on_dark_rgb'] }};
        --bs-link-hover-color: {{ $primary['hex'] }};
        --bs-primary-text-emphasis: {{ $primary['on_dark'] }};
    }

    [data-bs-theme="dark"] .btn-outline-primary {
        --bs-btn-color: {{ $primary['on_dark'] }};
        --bs-btn-border-color: {{ $primary['on_dark'] }};
    }

    [data-bs-theme="dark"] .btn-outline-secondary {
        --bs-btn-color: {{ $secondary['on_dark'] }};
        --bs-btn-border-color: {{ $secondary['on_dark'] }};
    }

    /* .text-secondary is handled in the stylesheet — it is not a brand colour.
       .text-primary is: Bootstrap leaves --bs-primary-rgb at whatever the shop
       chose, and that class reads exactly that. */
    [data-bs-theme="dark"] .text-primary { color: {{ $primary['on_dark'] }} !important; }
</style>
