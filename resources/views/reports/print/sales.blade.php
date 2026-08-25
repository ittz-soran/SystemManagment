@extends('layouts.print')

@section('title', __('Sales report'))
@section('doc-title', __('Sales report'))
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    {{-- These are the sales made in the period and everything that has since
         happened to them. The summary counts what happened IN the period, so a
         sale from last month returned this month lands on the two sheets
         differently. Both are right; they answer different questions. --}}
    <p class="small mb-3">
        {{ __('Sales made in this period, including anything returned against them since.') }}
    </p>

    @if($sales->isEmpty())
        <p class="text-center py-4">{{ __('No sales in this period.') }}</p>
    @else
        @php
            $totals = [
                'sold' => $sales->sum('total_amount'),
                'returned' => $sales->sum(fn ($s) => $s->returns->sum('total_amount')),
                'paid' => $sales->sum(fn ($s) => $s->amountPaid()),
                'cost' => collect($cost)->sum(),
                'cost_back' => collect($costReversed)->sum(),
            ];
        @endphp

        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Number') }}</th>
                <th>{{ __('Customer') }}</th>
                <th class="money">{{ __('Total') }}</th>
                <th class="money">{{ __('Paid') }}</th>
                <th class="money">{{ __('Owed') }}</th>
                <th class="money">{{ __('Profit') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($sales as $sale)
                @php
                    $returned = $sale->returns->sum('total_amount');
                    // What came back took its money off the sale AND put its
                    // cost back on the shelf. Both halves, or the sale is
                    // charged twice for a unit it no longer sold.
                    $profit = ($sale->total_amount - $returned)
                        - (($cost[$sale->id] ?? 0) - ($costReversed[$sale->id] ?? 0));
                @endphp
                <tr class="fw-semibold">
                    <td dir="ltr">{{ $sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td dir="ltr">{{ $sale->document_no }}</td>
                    <td>{{ $sale->customer->displayName() }}</td>
                    <td class="money">{{ money($sale->total_amount, false) }}</td>
                    <td class="money">{{ money($sale->amountPaid(), false) }}</td>
                    <td class="money">{{ money($sale->amountDue(), false) }}</td>
                    <td class="money">{{ money($profit, false) }}</td>
                </tr>

                {{-- Section 5: the cost against each line is the FIFO cost its
                     own movements recorded, so the profit here and the profit
                     on the summary are the same arithmetic. --}}
                @if($detailed)
                    @foreach($sale->items as $item)
                        {{-- The line's money sits under Total and nowhere
                             else: a figure under the Profit column reads as
                             that line's profit, which it is not. --}}
                        <tr class="small">
                            <td></td>
                            <td colspan="2" class="ps-3">
                                {{ $item->product->name }}
                                <span dir="ltr" class="ms-1">{{ $item->product->sku }}</span>
                                <span class="ms-2" dir="ltr">
                                    {{ number_format($item->quantity) }} × {{ money($item->unit_price, false) }}
                                </span>
                            </td>
                            <td class="money">{{ money($item->lineTotal(), false) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    @endforeach

                    @if($returned > 0)
                        <tr class="small">
                            <td></td>
                            <td colspan="2" class="ps-3">
                                {{ __('Returned') }}:
                                <span dir="ltr">{{ $sale->returns->pluck('document_no')->implode(', ') }}</span>
                            </td>
                            <td class="money">− {{ money($returned, false) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    @endif
                @endif
            @endforeach
            </tbody>
            <tfoot>
            <tr class="fw-bold border-top">
                <td colspan="3">
                    {{ trans_choice('{1}:count sale|[2,*]:count sales', $sales->count(), ['count' => number_format($sales->count())]) }}
                </td>
                <td class="money">{{ money($totals['sold'], false) }}</td>
                <td class="money">{{ money($totals['paid'], false) }}</td>
                <td class="money">{{ money($totals['sold'] - $totals['paid'], false) }}</td>
                <td class="money">
                    {{ money(($totals['sold'] - $totals['returned'])
                        - ($totals['cost'] - $totals['cost_back']), false) }}
                </td>
            </tr>
            @if($totals['returned'] > 0)
                <tr>
                    <td colspan="6">{{ __('Returned in this period') }}</td>
                    <td class="money">− {{ money($totals['returned'], false) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="6">{{ __('Cost of what was sold') }}</td>
                <td class="money">− {{ money($totals['cost'] - $totals['cost_back'], false) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif
@endsection
