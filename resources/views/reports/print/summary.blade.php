@extends('layouts.print')

@section('title', __('Summary report'))
@section('doc-title', __('Summary report'))
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    {{-- Section 10b, in the order the arithmetic actually runs, so the reader
         can follow it down the page rather than take it on trust. --}}
    <div class="h6 border-bottom pb-1 mb-2">{{ __('Profit') }}</div>
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

    <div class="row g-4">
        <div class="col-6">
            <div class="h6 border-bottom pb-1 mb-2">{{ __('Cash movement') }}</div>
            <table class="table table-sm">
                <tbody>
                @foreach($cash as $label => $amount)
                    <tr>
                        <td>{{ Str::headline($label) }}</td>
                        <td class="money">{{ money($amount, false) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="col-6">
            <div class="h6 border-bottom pb-1 mb-2">{{ __('Where you stand') }}</div>
            <table class="table table-sm">
                <tbody>
                @foreach($position as $label => $amount)
                    <tr>
                        <td>{{ Str::headline($label) }}</td>
                        <td class="money">{{ money($amount, false) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- The three trades behave nothing alike, and a single profit figure hides
         which of them carried the month. --}}
    <div class="h6 border-bottom pb-1 mb-2 mt-4">{{ __('Where the profit came from') }}</div>
    <table class="table table-sm mb-4">
        <thead>
        <tr>
            <th>{{ __('Kind') }}</th>
            <th class="money">{{ __('Revenue') }}</th>
            <th class="money">{{ __('Cost') }}</th>
            <th class="money">{{ __('Profit') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($byKind as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="money">{{ money($row['revenue'], false) }}</td>
                <td class="money">{{ money($row['cost'], false) }}</td>
                <td class="money">{{ money($row['profit'], false) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if(count($topProducts) > 0)
        <div class="h6 border-bottom pb-1 mb-2">{{ __('Top products') }}</div>
        <table class="table table-sm mb-4">
            <thead>
            <tr>
                <th>{{ __('Product') }}</th>
                <th class="money">{{ __('Quantity') }}</th>
                <th class="money">{{ __('Revenue') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($topProducts as $row)
                <tr>
                    <td>{{ $row['product']->name }}</td>
                    <td class="money">{{ number_format($row['units']) }}</td>
                    <td class="money">{{ money($row['revenue'], false) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if(count($expensesByCategory) > 0)
        <div class="h6 border-bottom pb-1 mb-2">{{ __('Expenses by category') }}</div>
        <table class="table table-sm">
            <tbody>
            @foreach($expensesByCategory as $row)
                <tr>
                    <td>{{ $row->category?->name ?? __('Uncategorised') }}</td>
                    <td class="money">{{ money($row->total, false) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
