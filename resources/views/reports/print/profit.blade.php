@extends('layouts.print')

@section('title', __('Where the profit came from'))
@section('doc-title', __('Where the profit came from'))
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    {{-- ─── 1. The chain, in the order the arithmetic runs ─────────────────── --}}
    <div class="h6 border-bottom pb-1 mb-2">{{ __('1. How the profit was worked out') }}</div>
    <table class="table table-sm mb-4">
        <tbody>
        @foreach([
            ['Sales', $profit['sales'], false],
            ['Sale returns', -$profit['sale_returns'], false],
            ['Revenue', $profit['revenue'], true],
            ['Cost of goods sold', -($profit['cogs'] - $profit['cogs_reversed']), false],
            ['Gross profit', $profit['gross_profit'], true],
            ['Discounts received', $profit['discounts_received'], false],
            ['Written off', -$profit['write_offs'], false],
            ['Faulty goods replaced', -$profit['swaps'], false],
            ['Expenses', -$profit['expenses'], false],
            ['Net profit', $profit['net'], true],
        ] as [$label, $amount, $strong])
            <tr class="{{ $strong ? 'fw-bold border-top' : '' }}">
                <td>{{ __($label) }}</td>
                <td class="money">{{ money($amount, false) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{-- ─── 2. The three trades ────────────────────────────────────────────── --}}
    <div class="h6 border-bottom pb-1 mb-2">{{ __('2. The three trades') }}</div>
    <table class="table table-sm mb-4">
        <thead>
        <tr>
            <th>{{ __('Kind') }}</th>
            <th class="money">{{ __('Sold') }}</th>
            <th class="money">{{ __('Revenue') }}</th>
            <th class="money">{{ __('Cost') }}</th>
            <th class="money">{{ __('Profit') }}</th>
            <th class="money">{{ __('Margin') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($byKind as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="money">{{ number_format($row['units']) }}</td>
                <td class="money">{{ money($row['revenue'], false) }}</td>
                <td class="money">{{ money($row['cost'], false) }}</td>
                <td class="money fw-semibold">{{ money($row['profit'], false) }}</td>
                <td class="money">{{ $row['margin'] }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="fw-bold border-top">
            <td>{{ __('Together') }}</td>
            <td class="money">{{ number_format(collect($byKind)->sum('units')) }}</td>
            <td class="money">{{ money(collect($byKind)->sum('revenue'), false) }}</td>
            <td class="money">{{ money(collect($byKind)->sum('cost'), false) }}</td>
            <td class="money">{{ money(collect($byKind)->sum('profit'), false) }}</td>
            <td></td>
        </tr>
        </tfoot>
    </table>

    {{-- ─── 3. By category ─────────────────────────────────────────────────── --}}
    <div class="h6 border-bottom pb-1 mb-2">{{ __('3. By category') }}</div>
    <table class="table table-sm mb-4">
        <thead>
        <tr>
            <th>{{ __('Category') }}</th>
            <th class="money">{{ __('Products') }}</th>
            <th class="money">{{ __('Sold') }}</th>
            <th class="money">{{ __('Revenue') }}</th>
            <th class="money">{{ __('Cost') }}</th>
            <th class="money">{{ __('Profit') }}</th>
            <th class="money">{{ __('Margin') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($byCategory as $row)
            <tr>
                <td>{{ $row->category }}</td>
                <td class="money">{{ number_format($row->products) }}</td>
                <td class="money">{{ number_format($row->units) }}</td>
                <td class="money">{{ money($row->revenue, false) }}</td>
                <td class="money">{{ money($row->cost, false) }}</td>
                <td class="money fw-semibold">{{ money($row->profit, false) }}</td>
                <td class="money">{{ $row->margin }}%</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="fw-bold border-top">
            <td colspan="2">{{ __('Together') }}</td>
            <td class="money">{{ number_format($byCategory->sum('units')) }}</td>
            <td class="money">{{ money($byCategory->sum('revenue'), false) }}</td>
            <td class="money">{{ money($byCategory->sum('cost'), false) }}</td>
            <td class="money">{{ money($byCategory->sum('profit'), false) }}</td>
            <td></td>
        </tr>
        </tfoot>
    </table>

    {{-- ─── 4. Every product ───────────────────────────────────────────────── --}}
    <div class="page-break"></div>
    <div class="h6 border-bottom pb-1 mb-2">
        {{ __('4. Every product that sold, best first') }}
    </div>
    <table class="table table-sm mb-4">
        <thead>
        <tr>
            <th>{{ __('Product') }}</th>
            <th>{{ __('Category') }}</th>
            <th class="money">{{ __('Sold') }}</th>
            <th class="money">{{ __('Revenue') }}</th>
            <th class="money">{{ __('Cost') }}</th>
            <th class="money">{{ __('Profit') }}</th>
            <th class="money">{{ __('Margin') }}</th>
            <th class="money">{{ __('Share') }}</th>
        </tr>
        </thead>
        <tbody>
        {{-- ⚠️ **A share of a loss is not a share** — Soran, 2026-09-26. This
             was `max(1, …)`, so a period whose profit came out negative — a day
             holding nothing but a refund does exactly that — divided by 1 and
             printed "-1050000%". The figure it is a share OF has to be a
             profit, or there is nothing to take a share of and the column says
             so. --}}
        @php $totalProfit = (int) $byProduct->sum('profit'); @endphp
        @foreach($byProduct as $row)
            <tr>
                <td>
                    {{ $row->name }}
                    <span class="app-code small" dir="ltr">{{ $row->sku }}</span>
                </td>
                <td>{{ $row->category ?? '—' }}</td>
                <td class="money">{{ number_format($row->units) }}</td>
                <td class="money">{{ money($row->revenue, false) }}</td>
                <td class="money">{{ money($row->cost, false) }}</td>
                <td class="money fw-semibold">{{ money($row->profit, false) }}</td>
                <td class="money">{{ $row->margin }}%</td>
                {{-- How much of the month this one product carried. --}}
                <td class="money">
                    {{ $totalProfit > 0 ? (int) round($row->profit / $totalProfit * 100).'%' : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="fw-bold border-top">
            <td colspan="2">{{ __('Together') }}</td>
            <td class="money">{{ number_format($byProduct->sum('units')) }}</td>
            <td class="money">{{ money($byProduct->sum('revenue'), false) }}</td>
            <td class="money">{{ money($byProduct->sum('cost'), false) }}</td>
            <td class="money">{{ money($byProduct->sum('profit'), false) }}</td>
            <td colspan="2"></td>
        </tr>
        </tfoot>
    </table>

    {{-- ─── 5. Every invoice line ──────────────────────────────────────────── --}}
    @if($showLines && $lines->isNotEmpty())
        <div class="page-break"></div>
        <div class="h6 border-bottom pb-1 mb-2">
            {{ __('5. Every line sold in the period') }}
            <span class="small fw-normal">
                {{ trans_choice('{1}one line|[2,*]:count lines', $lines->count(), ['count' => number_format($lines->count())]) }}
            </span>
        </div>
        <table class="table table-sm mb-4">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Product') }}</th>
                <th class="money">{{ __('Quantity') }}</th>
                <th class="money">{{ __('Unit price') }}</th>
                <th class="money">{{ __('Revenue') }}</th>
                <th class="money">{{ __('Cost') }}</th>
                <th class="money">{{ __('Profit') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($lines as $line)
                <tr>
                    <td dir="ltr">{{ $line->sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td class="app-code">{{ $line->sale->document_no }}</td>
                    <td>{{ $line->sale->customer?->displayName() }}</td>
                    <td>
                        {{ $line->product?->name }}
                        {{-- ⚠️ Sold before this period and brought back inside
                             it. The money and the cost are this period's, so
                             the row has to be here for the section to add up —
                             but its date is older than the sheet, and an
                             invoice from before the period with no explanation
                             reads as a fault. --}}
                        @if($line->refund_only ?? false)
                            <div class="small">{{ __('returned this period, sold before it') }}</div>
                        @endif
                    </td>
                    <td class="money">{{ number_format($line->units) }}</td>
                    <td class="money">{{ money($line->unit_price, false) }}</td>
                    <td class="money">{{ money($line->revenue, false) }}</td>
                    <td class="money">{{ money($line->cost, false) }}</td>
                    <td class="money fw-semibold">{{ money($line->profit, false) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="4">{{ __('Together') }}</td>
                <td class="money">{{ number_format($lines->sum('units')) }}</td>
                <td></td>
                <td class="money">{{ money($lines->sum('revenue'), false) }}</td>
                <td class="money">{{ money($lines->sum('cost'), false) }}</td>
                <td class="money">{{ money($lines->sum('profit'), false) }}</td>
            </tr>
            </tfoot>
        </table>

        {{-- ⚠️ The one figure on this sheet that belongs to no invoice line. --}}
        @if($profit['swaps'] !== 0)
            <p class="small">
                {{ __('The lines above carry the cost of what was sold. Replacing faulty goods cost a further :amount, which belongs to no invoice line — a swap leaves the invoice exactly as it was.', ['amount' => money($profit['swaps'], false)]) }}
            </p>
        @endif
    @endif

    {{-- ─── 6. What came off the profit ────────────────────────────────────── --}}
    <div class="page-break"></div>
    <div class="h6 border-bottom pb-1 mb-2">{{ __('6. What came off the profit') }}</div>

    <div class="h6 mt-3 mb-1">{{ __('Written off') }} — {{ money($profit['write_offs'], false) }}</div>
    @if($writeOffs->isEmpty())
        <p class="small mb-3">{{ __('Nothing was written off in this period.') }}</p>
    @else
        <table class="table table-sm mb-3">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Product') }}</th>
                <th class="money">{{ __('Quantity') }}</th>
                <th class="money">{{ __('Cost') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($writeOffs as $row)
                <tr>
                    <td dir="ltr">{{ $row->at->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td>{{ $row->product }}</td>
                    <td class="money">{{ number_format($row->units) }}</td>
                    <td class="money">{{ money($row->cost, false) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="h6 mt-3 mb-1">{{ __('Faulty goods replaced') }} — {{ money($profit['swaps'], false) }}</div>
    @if($swaps->isEmpty())
        <p class="small mb-3">{{ __('Nothing was replaced in this period.') }}</p>
    @else
        <table class="table table-sm mb-3">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Product') }}</th>
                <th class="money">{{ __('Quantity') }}</th>
                <th class="money">{{ __('Cost') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($swaps as $swap)
                <tr>
                    <td dir="ltr">{{ $swap->swapped_at->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td class="app-code">{{ $swap->document_no }}</td>
                    <td>{{ $swap->product?->name }}</td>
                    <td class="money">{{ number_format($swap->quantity) }}</td>
                    <td class="money">{{ money($swap->cost(), false) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="h6 mt-3 mb-1">{{ __('Expenses') }} — {{ money($profit['expenses'], false) }}</div>
    @if($expenses->isEmpty())
        <p class="small mb-3">{{ __('Nothing was spent in this period.') }}</p>
    @else
        <table class="table table-sm mb-3">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Note') }}</th>
                <th class="money">{{ __('Amount') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($expenses as $expense)
                <tr>
                    <td dir="ltr">{{ $expense->expense_date->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td>{{ $expense->category?->name ?? '—' }}</td>
                    <td>{{ $expense->note }}</td>
                    <td class="money">{{ money($expense->amount, false) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="small mt-4">
        {{ __('Cost is the FIFO cost recorded on the movements each sale consumed — never an average and never the purchase price on the product form. A service moves no stock, so its whole price is profit.') }}
    </p>
@endsection
