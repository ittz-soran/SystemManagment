{{--
    A government customer's sale invoice, written on the shop's own paper —
    PROJECT_DOC Section 9, Soran, 2026-10-20: "please use this without change
    any thing, and text need rtl if arabic and kurdish, and lrt if english".

    The paper is the picture the shop uploaded in the invoice designer, printed
    whole at 148 × 210 mm; nothing of it is drawn here. This page writes only
    the sale, each layer where the designer put it, in the shop's font. The
    item rows grow with their names and draw their line after; the browser
    moves what does not fit on to more sheets (resources/js/invoice-print.js).
    $fill is App\Support\GovernmentInvoice::fill(), the designer's too.
--}}
<!DOCTYPE html>
<html lang="{{ $currentLanguage }}" dir="ltr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->document_no }} · {{ setting('shop_name', config('app.name')) }}</title>

    {{-- The shop's fonts, which carry the Kurdish and Arabic letters. --}}
    @vite(['resources/scss/app.scss', 'resources/js/invoice-print.js'])
    @include('print.partials.invoice-font')
    @include('print.partials.invoice-layers-css')

    <style>
        /* No page margin: the paper is the whole sheet, edge to edge. */
        @page { size: A5 portrait; margin: 0; }

        body { background: #fff; margin: 0; }
        .inv-sheet { --mm: 1mm; }
        .inv-sheet + .inv-sheet { break-before: page; }

        .toolbar { display: flex; gap: 0.5rem; justify-content: flex-end; padding: 1rem; }

        @media screen {
            body { background: #e9ecef; }
            .inv-sheet { margin: 0 auto 2rem; box-shadow: 0 0.2rem 1rem rgba(0, 0, 0, 0.15); }
        }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <a href="{{ route('sales.print', ['sale' => $sale, 'paper' => 'a4']) }}" class="btn btn-outline-secondary">
        {{ __('Print the ordinary invoice instead') }}
    </a>
    <button type="button" class="btn btn-primary" data-print>{{ __('Print') }}</button>
</div>

<div class="inv-sheet">
    <img src="{{ $paper }}" alt="" class="inv-paper">
    @include('print.partials.invoice-layers', ['layout' => App\Support\InvoiceLayout::current(), 'fill' => $fill, 'designer' => false])
</div>
</body>
</html>
