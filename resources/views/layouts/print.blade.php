{{--
    Section 9b print views: a separate minimal layout — no sidebar, no buttons,
    black on white, logo and shop info from Settings.
--}}
<!DOCTYPE html>
<html lang="{{ $currentLanguage }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ setting('shop_name', config('app.name')) }}</title>

    @vite(['resources/scss/app.scss'])

    {{-- After the stylesheet, or Bootstrap's own :root wins on source order. --}}
    @include('partials.brand')

    <style>
        /* Black on white, whatever the viewer's theme. */
        body { background: #fff; color: #000; }

        .print-sheet { max-width: 20cm; margin: 0 auto; padding: 1.5rem; }

        /* Numbers and currency stay left-to-right even inside RTL text. */
        .money { text-align: end; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; }

        @media print {
            @page { margin: 12mm; }

            .no-print { display: none !important; }
            .print-sheet { max-width: none; padding: 0; }

            /* Screen-perfect tables often break across printed pages. */
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
<div class="print-sheet">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button class="btn btn-primary" onclick="window.print()">
            {{ __('Print') }}
        </button>
    </div>

    <header class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            @if(shop_logo())
                {{-- Logos must not mirror in RTL. --}}
                <img src="{{ shop_logo() }}" alt="" height="48" class="mb-2">
            @endif
            <div class="h5 mb-1">{{ setting('shop_name', config('app.name')) }}</div>
            @if(setting('shop_address'))
                <div class="small">{{ setting('shop_address') }}</div>
            @endif
            <div class="small" dir="ltr">
                {{ collect([setting('shop_phone'), setting('shop_phone_2')])->filter()->implode(' · ') }}
            </div>
            @if(setting('shop_email'))
                <div class="small" dir="ltr">{{ setting('shop_email') }}</div>
            @endif
        </div>

        <div class="text-end">
            <div class="h5 mb-1">@yield('doc-title')</div>
            <div class="fw-semibold" dir="ltr">@yield('doc-number')</div>
            <div class="small" dir="ltr">@yield('doc-date')</div>
        </div>
    </header>

    @yield('content')

    @if(setting('invoice_footer'))
        <footer class="border-top pt-3 mt-4 small text-center">
            {{ setting('invoice_footer') }}
        </footer>
    @endif
</div>
</body>
</html>
