{{--
    The layers of the A5 government invoice, placed by the shop's layout —
    PROJECT_DOC Section 9, the invoice designer.

    $layout   InvoiceLayout::current(), or what the designer is editing
    $fill     what each layer says, the invoice options already applied —
              GovernmentInvoice::present(): customer, date, address, rows
              (price, qty, name, amount), words, total, number
    $designer true in the designer: every layer is drawn, a hidden one dimmed,
              each named and marked for dragging

    Each value reads in its own direction — Soran, 2026-10-20: "text need rtl
    if arabic and kurdish, and lrt if english". Kurdish and Arabic start at the
    right of their box, English at the left; figures and dates left to right;
    the words are Kurdish and right to left.
--}}
@foreach($layout as $key => $layer)
    @continue(! $designer && ! $layer['show'])
    @if($key === 'lines')
        <div class="inv-layer inv-lines {{ $layer['bold'] ? 'is-bold' : '' }} {{ $layer['show'] ? '' : 'is-off' }}"
             data-layer="lines"
             style="--x: {{ $layer['x'] }}; --y: {{ $layer['y'] }}; --w: {{ $layer['w'] }}; --h: {{ $layer['h'] }}; --size: {{ $layer['size'] }}; --color: {{ $layer['color'] ?? App\Support\InvoiceLayout::INK }}; --row: {{ $layer['row'] }}; --c1: {{ $layer['columns'][0] }}; --c2: {{ $layer['columns'][1] }}; --c3: {{ $layer['columns'][2] }};">
            @foreach($fill['rows'] as $row)
                <div class="inv-row" data-item>
                    <div class="inv-num" data-cell="price">{{ $row['price'] }}</div>
                    {{-- "١ دانە" reads right to left, "1 pcs" left to right. --}}
                    <div class="inv-qty" dir="auto" data-cell="qty">{{ $row['qty'] }}</div>
                    <div class="inv-name" dir="auto" data-cell="name">{{ $row['name'] }}</div>
                    <div class="inv-num" data-cell="amount">{{ $row['amount'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <div class="inv-layer inv-one {{ match ($key) {
                'date' => 'is-ltr',
                'words' => 'is-middle',
                'total' => 'is-ltr is-centred is-middle',
                'number' => 'is-ltr is-centred',
                default => '',
            } }} {{ $layer['bold'] ? 'is-bold' : '' }} {{ $layer['show'] ? '' : 'is-off' }}"
             data-layer="{{ $key }}"
             @if(in_array($key, ['words', 'total'], true)) data-last-only @endif
             dir="{{ match ($key) { 'customer', 'address' => 'auto', 'words' => 'rtl', default => 'ltr' } }}"
             style="--x: {{ $layer['x'] }}; --y: {{ $layer['y'] }}; --w: {{ $layer['w'] }}; --h: {{ $layer['h'] }}; --size: {{ $layer['size'] }}; --color: {{ $layer['color'] ?? App\Support\InvoiceLayout::INK }};">
            <span class="inv-value">{{ $fill[$key] ?? '' }}</span>
        </div>
    @endif
@endforeach
