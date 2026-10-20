{{--
    A government customer's sale invoice, written on the shop's own paper —
    PROJECT_DOC Section 9, Soran, 2026-10-20: *"please use this without change
    any thing, and text need rtl if arabic and kurdish, and lrt if english"*.

    The paper is the picture the shop uploaded in Settings, printed whole at
    148 × 210 mm; nothing of it is drawn here. This page writes only the sale,
    at the places of Soran's book, measured off his PDF in millimetres from the
    top left of the sheet. A paper laid out differently would not line up —
    that is what the drawn page, print/sale-government, is for.

    Every value reads in its own direction: dir="auto" and aligned to its start,
    so Kurdish and Arabic begin at the right-hand end of their line or cell and
    English at the left. Figures and dates are left to right.
--}}
@php
    // ONE currency on a receipt — Section 2b. The rate comes off the document.
    $writtenIn = $sale->writtenIn();
    $say = fn (int $base) => $writtenIn ? $sale->asWritten($base) : money($base, false);
    $mark = $writtenIn ? $writtenIn->mark() : App\Support\Money::base()->mark();

    // The ruled lines of his paper, top and bottom of each, eleven of them.
    $rules = [81.3, 89.2, 97.25, 105.3, 113.85, 121.9, 129.9, 138.2, 146.25, 154.8, 162.85, 171.45];
    $perSheet = count($rules) - 1;

    // A longer sale goes on as many sheets as it needs; the total on the last.
    $sheets = $sale->items->values()->chunk($perSheet);
    if ($sheets->isEmpty()) {
        $sheets = collect([collect()]);
    }

    $date = $sale->sale_date->format(setting('date_format', 'Y-m-d'));
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLanguage }}" dir="ltr" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->document_no }} · {{ setting('shop_name', config('app.name')) }}</title>

    {{-- The shop's fonts, which carry the Kurdish and Arabic letters. --}}
    @vite(['resources/scss/app.scss'])

    <style>
        /* No page margin: the paper is the whole sheet, edge to edge. */
        @page { size: A5 portrait; margin: 0; }

        body { background: #fff; color: #111; font-family: var(--bs-body-font-family); margin: 0; }

        .sheet { position: relative; width: 148mm; height: 210mm; overflow: hidden; break-after: page; }
        .sheet:last-of-type { break-after: auto; }
        .sheet .paper { position: absolute; inset: 0; width: 148mm; height: 210mm; }

        /* One value at one place. Its text sits on the box's bottom edge. */
        .at { position: absolute; display: flex; align-items: flex-end; white-space: nowrap; font-size: 10pt; line-height: 1.25; }
        .at.ltr { direction: ltr; }
        .at.mid { justify-content: center; }
        .at.cell { align-items: center; overflow: hidden; }
        .at.cell.wrap { white-space: normal; font-size: 9pt; line-height: 1.15; }
        .at.strong { font-weight: 700; }

        .toolbar { display: flex; gap: 0.5rem; justify-content: flex-end; padding: 1rem; }

        @media screen {
            body { background: #e9ecef; }
            .sheet { margin: 0 auto 2rem; box-shadow: 0 0.2rem 1rem rgba(0, 0, 0, 0.15); background: #fff; }
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
    <button class="btn btn-primary" onclick="window.print()">{{ __('Print') }}</button>
</div>

@foreach($sheets as $lines)
    <div class="sheet">
        <img src="{{ $paper }}" alt="" class="paper">

        {{-- Mr./ M/s. — بەڕێز/ السيد : the line runs 20.5 to 125.2, 59.5 down. --}}
        <div class="at customer" dir="auto" style="left: 21mm; width: 103.7mm; top: 54.5mm; height: 5.4mm;">{{ $sale->soldTo() }}</div>

        {{-- Date — بەروار / التاريخ : 14.4 to 40.8, 69.5 down. --}}
        <div class="at date ltr" style="left: 15mm; width: 25.4mm; top: 64.7mm; height: 5.4mm;">{{ $date }}</div>

        {{-- Address — ناونیشان/ العنوان : 83.7 to 117.0, 69.5 down. --}}
        <div class="at address" dir="auto" style="left: 84.2mm; width: 32.4mm; top: 64.7mm; height: 5.4mm;">{{ $sale->customer->address }}</div>

        {{-- The columns: Price 2.4–28.0, Qty 28.2–41.8, Content 42.0–118.5, Amount 118.6–145.5. --}}
        @foreach($lines as $i => $item)
            @php
                $n = $i % $perSheet;
                $top = $rules[$n] + 0.3;
                $height = $rules[$n + 1] - $rules[$n] - 0.6;
                $row = "top: {$top}mm; height: {$height}mm;";
            @endphp
            <div class="at cell mid ltr price" style="left: 2.6mm; width: 25.2mm; {{ $row }}">{{ $say($item->unit_price) }}</div>
            <div class="at cell mid ltr qty" style="left: 28.4mm; width: 13.2mm; {{ $row }}">{{ qty($item->quantity, $item->product->unit) }}</div>
            <div class="at cell wrap content" dir="auto" style="left: 43.2mm; width: 74mm; {{ $row }}">{{ $item->product->name }}</div>
            <div class="at cell mid ltr amount" style="left: 118.8mm; width: 26.5mm; {{ $row }}">{{ $say($item->lineTotal()) }}</div>
        @endforeach

        @if($loop->last)
            {{-- The total band, 171.8 to 180.1 down. The words, Kurdish, sit
                 against کۆی گشتی/ المجموع, which begins 95.5 across. --}}
            <div class="at cell in-words strong" dir="rtl" style="left: 4mm; width: 90mm; top: 172mm; height: 7.9mm;">{{ $inWords }}</div>
            <div class="at cell mid ltr total strong" style="left: 118.8mm; width: 26.5mm; top: 172mm; height: 7.9mm;">{{ $say($sale->total_amount) }} {{ $mark }}</div>
        @endif

        {{-- Inv. No. — ژ.م وەصڵ : 18.5 to 37.0, 201.3 down. --}}
        <div class="at mid ltr inv strong" style="left: 17.5mm; width: 20.5mm; top: 196.4mm; height: 5.4mm; font-size: 9pt;">{{ $sale->document_no }}</div>
    </div>
@endforeach
</body>
</html>
