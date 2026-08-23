@extends('layouts.app')

@section('title', __('Reports'))
@section('subheading')
    <span dir="ltr">{{ $from->format(setting('date_format', 'Y-m-d')) }} → {{ $to->format(setting('date_format', 'Y-m-d')) }}</span>
@endsection

@section('actions')
    <button class="btn btn-outline-secondary" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>{{ __('Print') }}
    </button>
@endsection

@section('content')
    {{-- A printed page leaves the screen behind: no sidebar, no heading, no
         idea which shop or which dates. This says all three, on paper only. --}}
    <div class="d-none d-print-block mb-3 border-bottom pb-2">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="fs-5 fw-semibold">{{ setting('shop_name', config('app.name')) }}</div>
                <div>{{ __('Reports') }}</div>
            </div>
            <div class="text-end small">
                <div dir="ltr">
                    {{ $from->format(setting('date_format', 'Y-m-d')) }}
                    → {{ $to->format(setting('date_format', 'Y-m-d')) }}
                </div>
                <div class="text-secondary" dir="ltr">
                    {{ __('Printed :when', ['when' => now()->format(setting('date_format', 'Y-m-d').' H:i')]) }}
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="card card-body mb-3 no-print">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Apply') }}</button>
                <a href="{{ route('reports.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('This month') }}</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        @foreach([
            ['label' => __('Revenue'), 'value' => $profit['revenue'], 'note' => __('Sales less returns')],
            ['label' => __('Gross profit'), 'value' => $profit['gross_profit'], 'note' => __('Revenue less FIFO cost')],
            ['label' => __('Net'), 'value' => $profit['net'], 'note' => __('After discounts, write-offs and expenses')],
            ['label' => __('Stock value'), 'value' => $position['stock_value'], 'note' => __('At FIFO cost, right now')],
        ] as $card)
            <div class="col-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">{{ $card['label'] }}</div>
                        <div class="fs-4 fw-semibold money {{ $card['value'] < 0 ? 'text-danger' : '' }}">
                            {{ money($card['value']) }}
                        </div>
                        <div class="small text-secondary">{{ $card['note'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">{{ __('Profit') }}</div>
                <div class="table-responsive">
                    {{-- The Section 10b profit table, line for line. --}}
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                        {{-- The sign is its own column so the page can be read
                             down like a sum done on paper: the pluses and
                             minuses lead to each bold line, and each bold line
                             is a total that can be checked by adding up the
                             ones above it. --}}
                        @foreach([
                            ['+', __('Sales'), $profit['sales'], false],
                            ['−', __('Less returns'), $profit['sale_returns'], false],
                            ['=', __('Revenue'), $profit['revenue'], true],
                            ['−', __('FIFO cost of goods sold'), $profit['cogs'], false],
                            ['+', __('Cost reversed by returns'), $profit['cogs_reversed'], false],
                            ['=', __('Gross profit'), $profit['gross_profit'], true],
                            ['+', __('Discounts received'), $profit['discounts_received'], false],
                            ['−', __('Stock written off'), $profit['write_offs'], false],
                            ['−', __('Expenses'), $profit['expenses'], false],
                            ['=', __('Net'), $profit['net'], true],
                        ] as [$sign, $label, $value, $strong])
                            <tr class="{{ $strong ? 'fw-semibold border-top' : '' }}">
                                <td class="text-secondary" style="width: 1.5rem">{{ $sign }}</td>
                                <td>{{ $label }}</td>
                                <td class="money {{ $strong && $value < 0 ? 'text-danger' : '' }}">
                                    {{ money($value, false) }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header">{{ __('Cash movement') }}</div>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <tr>
                        <td>{{ __('In') }}</td>
                        <td class="money text-success">{{ money($cash['in'], false) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('Out') }}</td>
                        <td class="money text-danger">{{ money($cash['out'], false) }}</td>
                    </tr>
                    <tr class="fw-semibold border-top">
                        <td>{{ __('Net') }}</td>
                        <td class="money">{{ money($cash['net'], false) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="card-header">{{ __('Where you stand') }}</div>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <tr>
                        <td>{{ __('Purchases in this period') }}</td>
                        <td class="money">{{ money($profit['purchases'], false) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('Returned to suppliers') }}</td>
                        <td class="money">{{ money($profit['purchase_returns'], false) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('Customers owe the shop') }}</td>
                        <td class="money">{{ money($position['customers_owe'], false) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('The shop owes suppliers') }}</td>
                        <td class="money">{{ money($position['owed_to_suppliers'], false) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- The three trades, side by side. Stock turns over on a thin margin,
             a second-hand machine is a few large bets, and a service is all
             margin because it costs nothing to give — one gross-profit figure
             hides which of the three is carrying the month. --}}
        @if($byKind !== [])
            <div class="col-12">
                <div class="card">
                    <div class="card-header">{{ __('Where the profit came from') }}</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
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
                                    <td class="fw-medium">{{ $row['label'] }}</td>
                                    <td class="money text-secondary">{{ number_format($row['units']) }}</td>
                                    <td class="money">{{ money($row['revenue'], false) }}</td>
                                    <td class="money text-secondary">{{ money($row['cost'], false) }}</td>
                                    <td class="money fw-semibold {{ $row['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ money($row['profit'], false) }}
                                    </td>
                                    {{-- What is left of every 100 taken, so the
                                         three can be compared without dividing. --}}
                                    <td class="money text-secondary">{{ $row['margin'] }}%</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr class="fw-semibold border-top">
                                <td>{{ __('Together') }}</td>
                                <td class="money">{{ number_format(collect($byKind)->sum('units')) }}</td>
                                <td class="money">{{ money(collect($byKind)->sum('revenue'), false) }}</td>
                                <td class="money">{{ money(collect($byKind)->sum('cost'), false) }}</td>
                                <td class="money">{{ money(collect($byKind)->sum('profit'), false) }}</td>
                                <td></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">{{ __('Top products') }}</div>
                @if($topProducts->isEmpty())
                    <x-empty-state icon="box-seam" :message="__('Nothing sold in this period.')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th class="money">{{ __('Units') }}</th>
                                <th class="money">{{ __('Revenue') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($topProducts as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('products.show', $row['product']) }}" class="text-decoration-none">
                                            {{ $row['product']->name }}
                                        </a>
                                    </td>
                                    <td class="money">{{ number_format($row['units']) }}</td>
                                    <td class="money">{{ money($row['revenue'], false) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">{{ __('Expenses by category') }}</div>
                @if($expensesByCategory->isEmpty())
                    <x-empty-state icon="cash-stack" :message="__('No expenses in this period.')" />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                            @foreach($expensesByCategory as $row)
                                <tr>
                                    <td>{{ $row->category->name }}</td>
                                    <td class="money">{{ money($row->total, false) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
