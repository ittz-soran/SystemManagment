@php
    $size = $spec['size'];
    $fields = $spec['fields'];
    $product = $spec['product'];

    // Less than the full width: the bars need a quiet zone either side or a
    // scanner cannot find where the code starts.
    $barcodeWidth = $labels->barcodeWidth($spec['code'], $size);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <title>{{ $product->name }} · {{ $product->barcode }}</title>

    <style>
        /* Exactly the label, with no page margin: the printer advances one
           label per page, so a run of ten copies is ten of these. */
        @page {
            size: {{ $size['width'] }}mm {{ $size['height'] }}mm;
            margin: 0;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: system-ui, sans-serif;
        }

        .label {
            width: {{ $size['width'] }}mm;
            height: {{ $size['height'] }}mm;
            padding: {{ $size['padding'] }}mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.4mm;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
        }

        .label:last-child { page-break-after: auto; break-after: auto; }

        .shop { font-size: {{ $size['small'] }}pt; font-weight: 600; }

        .name {
            font-size: {{ $size['name'] }}pt;
            font-weight: 600;
            text-align: center;
            line-height: 1.1;
            /* A name that wrapped would push the barcode off the label. */
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
        }

        .barcode svg { display: block; }

        /* Numbers stay left-to-right even when the interface is Sorani. */
        .digits {
            font-size: {{ $size['small'] }}pt;
            letter-spacing: 0.3mm;
            font-variant-numeric: tabular-nums;
            direction: ltr;
        }

        .footer {
            width: 100%;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1mm;
        }

        .sku { font-size: {{ $size['small'] }}pt; direction: ltr; }
        .price { font-size: {{ $size['price'] }}pt; font-weight: 700; direction: ltr; margin-inline-start: auto; }

        /* On screen only — the sheet is previewed before it is printed. */
        @media screen {
            body { background: #e9ecef; padding: 1rem; }

            .label {
                background: #fff;
                margin: 0 auto 0.5rem;
                border: 1px solid #adb5bd;
                box-shadow: 0 1px 3px rgba(0, 0, 0, .15);
            }

            .toolbar {
                max-width: 30rem;
                margin: 0 auto 1rem;
                font-family: system-ui, sans-serif;
                text-align: center;
            }

            .toolbar button, .toolbar a {
                font: inherit;
                padding: .5rem 1rem;
                border-radius: .375rem;
                border: 1px solid #adb5bd;
                background: #fff;
                cursor: pointer;
                text-decoration: none;
                color: inherit;
            }

            .warning { color: #664d03; background: #fff3cd; padding: .5rem .75rem; border-radius: .375rem; margin-bottom: .75rem; }
        }

        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        @if($spec['warning'])
            <div class="warning">{{ $spec['warning'] }}</div>
        @endif

        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <a href="{{ route('products.show', $product) }}">{{ __('Back to the product') }}</a>

        <p style="font-size: .85rem; color: #495057">
            {{ __('In the print dialog set the paper size to :size and the margins to none.', [
                'size' => $size['width'].' × '.$size['height'].' mm',
            ]) }}
        </p>
    </div>

    @for($copy = 0; $copy < $spec['copies']; $copy++)
        <div class="label">
            @if($fields['shop'] && setting('shop_name'))
                <div class="shop">{{ setting('shop_name') }}</div>
            @endif

            @if($fields['name'])
                <div class="name">{{ $labels->trim($product->name, $size) }}</div>
            @endif

            <div class="barcode">
                {!! $labels->svg($spec['code'], $barcodeWidth, $size['barcode']) !!}
            </div>

            @if($fields['barcode_number'])
                <div class="digits">{{ $spec['code'] }}</div>
            @endif

            @if($fields['sku'] || $fields['price'])
                <div class="footer">
                    @if($fields['sku'])<span class="sku">{{ $product->sku }}</span>@endif
                    @if($fields['price'])<span class="price">{{ money($product->sale_price) }}</span>@endif
                </div>
            @endif
        </div>
    @endfor

    <script>
        // Straight into the print dialog when asked for, so the flow from the
        // product page is one click rather than two.
        if (new URLSearchParams(location.search).get('auto') === '1') {
            window.addEventListener('load', () => window.print());
        }
    </script>
</body>
</html>
