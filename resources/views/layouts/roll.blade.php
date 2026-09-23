<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ckb', 'ar', 'fa']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>

    {{-- ⚠️ A roll, not a sheet — Soran, 2026-09-21: "tickets printed in 80mm
         rolled paper". 80mm is the paper; 72mm is what the printer actually
         puts ink on, and the rest is the margin the mechanism needs. The page
         has no fixed height: a roll is cut where the content stops. --}}
    <style>
        @page { size: 80mm auto; margin: 0; }

        :root { color-scheme: light; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f1f1f1;
            color: #000;
            font-family: var(--bs-body-font-family, system-ui, sans-serif);
            /* Thermal print is coarse. Small type survives it badly, and a
               photograph of it on a phone worse still. */
            font-size: 12px;
            line-height: 1.35;
        }

        .roll {
            width: 80mm;
            padding: 4mm;
            margin: 8px auto;
            background: #fff;
            /* On screen only; a roll has no shadow. */
            box-shadow: 0 1px 6px rgba(0, 0, 0, .18);
        }

        h1 { font-size: 15px; margin: 0 0 1mm; text-align: center; }
        .muted { color: #444; }
        .center { text-align: center; }
        .small { font-size: 11px; }
        .big { font-size: 14px; font-weight: 700; }

        hr {
            border: 0;
            border-top: 1px dashed #999;
            margin: 2.5mm 0;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .6mm 0; vertical-align: top; text-align: start; }

        /* Figures stay left-to-right and line up, in all four languages. */
        .num {
            text-align: end;
            font-variant-numeric: tabular-nums;
            direction: ltr;
            unicode-bidi: isolate;
            white-space: nowrap;
        }

        .row { display: flex; justify-content: space-between; gap: 2mm; }

        .barcode { margin: 2mm 0 1mm; }
        .barcode svg { display: block; margin-inline: auto; }

        .no-print { text-align: center; margin: 10px 0 20px; }

        @media print {
            body { background: #fff; }
            .roll { margin: 0; box-shadow: none; width: auto; padding: 2mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

{{-- ⚠️ Shown on screen first, deliberately — "not ready printer always, so show
     ticket on screen and customer take photo and have barcode and use this
     photo for collection his device". So the screen view is the real one and
     printing is the extra, not the other way round. --}}
<div class="no-print">
    <button onclick="window.print()"
            style="font: inherit; padding: 6px 18px; cursor: pointer">
        {{ __('Print') }}
    </button>
</div>

<div class="roll">
    @yield('content')
</div>

</body>
</html>
