{{--
    A government customer's sale invoice — PROJECT_DOC Section 9, Soran,
    2026-10-20: *"this type need A5 invoice"*, and the legal invoice book he
    sent the same day, which this copies line for line.

    A page of its own rather than layouts.print: the paper size, the banner and
    the ruled table are the whole of it, and none of them is the ordinary
    invoice's. The labels are the paper's, in its three languages, whatever the
    screen is in — an official document reads the same whoever printed it. The
    shop's own details all come from Settings, so a shop that is not Soran's
    prints its own name, logo and colour.
--}}
@php
    $brand = brand_palette(setting('primary_color', '#0d6efd'));
    $logo = shop_logo();
    // ⚠️ `/u`, or \R also matches the byte 0x85 — the second half of the
    // letter م in UTF-8 — and "پرۆگرامسازی" is cut in two. Found by looking at
    // the printed page; every test that typed English passed.
    $tagline = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) setting('invoice_tagline', '')))));
    $phones = collect([setting('shop_phone'), setting('shop_phone_2')])->filter()->implode(' - ');

    // ONE currency on a receipt — Section 2b. The rate comes off the document.
    $writtenIn = $sale->writtenIn();
    $say = fn (int $base) => $writtenIn ? $sale->asWritten($base) : money($base, false);
    $mark = $writtenIn ? $writtenIn->mark() : App\Support\Money::base()->mark();

    // The book has ten ruled lines; a short invoice still looks like it.
    $blankRows = max(0, 10 - $sale->items->count());

    // $inWords comes from PrintController: Kurdish, after تەنها.
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLanguage }}" dir="ltr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->document_no }} · {{ setting('shop_name', config('app.name')) }}</title>

    {{-- The shop's fonts, which carry the Kurdish and Arabic letters. --}}
    @vite(['resources/scss/app.scss'])
    @include('partials.brand')
    {{-- The shop's invoice font, from the invoice designer, when it has one. --}}
    @include('print.partials.invoice-font')

    <style>
        @page { size: A5 portrait; margin: 8mm; }

        :root {
            --fill: {{ $brand['hex'] }};
            --on-fill: {{ $brand['on'] }};
            --name: {{ $brand['on_dark'] }};
            --banner: #3a3b3e;
            --rule: #1d1d1f;
            --warn: #d0021b;
        }

        body { background: #fff; color: #111; font-family: var(--invoice-font); font-size: 9.5pt; margin: 0; }
        .sheet { width: 132mm; margin: 0 auto; }
        .rtl { direction: rtl; unicode-bidi: isolate; }

        .banner { background: var(--banner); color: #fff; border-radius: 3mm; display: flex; align-items: center; gap: 3mm; padding: 3mm 4mm; }
        .banner .logo { width: 17mm; height: 17mm; border-radius: 50%; background: #fff; object-fit: contain; flex: none; }
        .banner .tagline { flex: 1; text-align: center; font-size: 7.5pt; line-height: 1.45; }
        .banner .names { text-align: end; line-height: 1.15; }
        .banner .ku { color: var(--name); font-size: 17pt; font-weight: 700; }
        .banner .en { font-size: 15pt; font-weight: 700; }

        .field { display: flex; align-items: baseline; gap: 1.5mm; margin-top: 3.5mm; font-size: 9pt; }
        .field .value { flex: 1; border-bottom: 0.25mm dotted #333; min-height: 5mm; padding: 0 1mm; text-align: start; }
        .field .label { white-space: nowrap; }

        .table-wrap { position: relative; margin-top: 3.5mm; }
        .watermark { position: absolute; inset: 9mm 0 9mm 0; margin: auto; width: 45mm; height: 45mm; object-fit: contain; opacity: 0.08; z-index: 0; }
        .lines { position: relative; z-index: 1; width: 100%; border-collapse: collapse; border: 0.4mm solid var(--rule); table-layout: fixed; }
        .lines th { background: var(--fill); color: var(--on-fill); border: 0.3mm solid var(--rule); padding: 0.8mm 1mm; font-size: 7.5pt; line-height: 1.2; font-weight: 600; text-align: center; }
        .lines td { border-inline: 0.3mm solid var(--rule); border-bottom: 0.15mm solid #b9b9b9; padding: 0.8mm 1.5mm; height: 6.2mm; font-size: 9pt; vertical-align: middle; }
        .lines .num { text-align: center; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .lines tfoot td { background: var(--fill); color: var(--on-fill); border: 0.3mm solid var(--rule); font-weight: 700; height: 9mm; }
        .lines tfoot .band { display: flex; justify-content: flex-end; align-items: center; gap: 3mm; }
        .lines tfoot .in-words { font-size: 8.5pt; }
        .lines tfoot .label { text-align: end; line-height: 1.2; font-size: 8.5pt; white-space: nowrap; }
        .col-price { width: 23mm; } .col-qty { width: 13mm; } .col-amount { width: 26mm; }

        .notes { display: flex; justify-content: space-between; gap: 4mm; margin-top: 2.5mm; color: var(--warn); font-size: 8pt; line-height: 1.3; }
        .notes .end { text-align: end; }

        /* The book's own empty room for the signature and the stamp. */
        .signing-room { height: 24mm; }

        .foot { margin-top: 4mm; font-size: 8.5pt; }
        .foot .row-line { display: flex; justify-content: space-between; align-items: baseline; gap: 3mm; }
        .foot .row-line + .row-line { margin-top: 1.5mm; }
        .foot .inv { font-weight: 700; direction: ltr; }

        .toolbar { display: flex; gap: 0.5rem; justify-content: flex-end; padding: 1rem; }

        @media screen {
            body { background: #e9ecef; }
            .sheet { width: 148mm; min-height: 210mm; padding: 8mm; box-sizing: border-box; background: #fff; box-shadow: 0 0.2rem 1rem rgba(0, 0, 0, 0.15); margin-bottom: 2rem; }
        }

        @media print {
            .no-print { display: none !important; }
            .lines thead { display: table-header-group; }
            .lines tr { page-break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <a href="{{ route('sales.print', ['sale' => $sale, 'paper' => 'a4']) }}" class="btn btn-outline-secondary">
        {{ __('Print the ordinary invoice instead') }}
    </a>
    <button class="btn btn-primary" onclick="window.print()">{{ __('Print') }}</button>
</div>

<div class="sheet">
    <header class="banner">
        @if($logo)
            {{-- Logos must not mirror in RTL. --}}
            <img src="{{ $logo }}" alt="" class="logo">
        @endif

        @if($tagline !== [])
            <div class="tagline">
                @foreach($tagline as $line)
                    <div dir="auto">{{ $line }}</div>
                @endforeach
            </div>
        @endif

        <div class="names">
            @if(setting('shop_name_ku'))
                <div class="ku rtl">{{ setting('shop_name_ku') }}</div>
            @endif
            <div class="en">{{ setting('shop_name', config('app.name')) }}</div>
        </div>
    </header>

    <div class="field">
        <span class="label">Mr./ M/s. :</span>
        <span class="value" dir="auto">{{ $sale->soldTo() }}</span>
        <span class="label rtl">بەڕێز/ السيد :</span>
    </div>

    <div class="field">
        <span class="label">Date :</span>
        <span class="value" dir="ltr">{{ $sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</span>
        <span class="label rtl">بەروار / التاريخ</span>
        <span class="label">&nbsp;Address :</span>
        <span class="value" dir="auto">{{ $sale->customer->address }}</span>
        <span class="label rtl">ناونیشان/ العنوان :</span>
    </div>

    <div class="table-wrap">
        @if($logo)
            <img src="{{ $logo }}" alt="" class="watermark">
        @endif

        <table class="lines">
            <colgroup>
                <col class="col-price"><col class="col-qty"><col><col class="col-amount">
            </colgroup>
            <thead>
            <tr>
                <th><span class="rtl">نرخ/ السعر</span><br>Price</th>
                <th><span class="rtl">بڕ/ الكمية</span><br>Qty</th>
                <th><span class="rtl">ناوەڕۆک/ محتويات</span><br>Content</th>
                <th><span class="rtl">کۆ/ إجمالي</span><br>Amount</th>
            </tr>
            </thead>
            <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td class="num">{{ $say($item->unit_price) }}</td>
                    <td class="num">{{ qty($item->quantity, $item->product->unit) }}</td>
                    <td dir="auto">{{ $item->product->name }}</td>
                    <td class="num">{{ $say($item->lineTotal()) }}</td>
                </tr>
            @endforeach
            @for($i = 0; $i < $blankRows; $i++)
                <tr><td></td><td></td><td></td><td></td></tr>
            @endfor
            </tbody>
            <tfoot>
            <tr>
                <td colspan="3">
                    <div class="band">
                        <span class="in-words rtl">{{ $inWords }}</span>
                        <span class="label"><span class="rtl">کۆی گشتی/ المجموع</span><br>The Total</span>
                    </div>
                </td>
                <td class="num">{{ $say($sale->total_amount) }} {{ $mark }}</td>
            </tr>
            </tfoot>
        </table>
    </div>

    <div class="notes">
        <div><span class="rtl">واژوو/ توقيع</span><br>Signature</div>
        <div class="end">
            <span class="rtl">هەڵە دەگەڕێتەوە بۆ هەردوو لا/ الغلط و السهو مرجوع للطرفين.</span><br>
            Fault goes back to both sides.
        </div>
    </div>

    {{-- Nothing added to the book — Soran, 2026-10-20: it "already have aria
         to signurate and stamp". This is that room. --}}
    <div class="signing-room"></div>

    <footer class="foot">
        <div class="row-line">
            <span class="inv">Inv. No. : {{ $sale->document_no }}</span>
            <span class="rtl">ژ.م وەصڵ :</span>
        </div>
        <div class="row-line">
            <span dir="ltr">@if($phones)☎ {{ $phones }}@endif</span>
            <span dir="ltr">{{ setting('shop_website') }}</span>
        </div>
    </footer>
</div>
</body>
</html>
