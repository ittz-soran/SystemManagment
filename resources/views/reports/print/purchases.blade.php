@extends('layouts.print')

@section('title', __('Purchases report'))
@section('doc-title', __('Purchases report'))
@section('doc-date', $from->format(setting('date_format', 'Y-m-d')).' — '.$to->format(setting('date_format', 'Y-m-d')))

@section('content')
    @include('reports.print._period')

    @if($purchases->isEmpty())
        <p class="text-center py-4">{{ __('No purchases in this period.') }}</p>
    @else
        @php
            $totals = [
                'bought' => $purchases->sum('grand_total'),
                'discount' => $purchases->sum('discount_amount'),
                'returned' => $purchases->sum(fn ($p) => $p->returns->sum('total_amount')),
                'paid' => $purchases->sum(fn ($p) => $p->amountPaid()),
            ];
        @endphp

        <table class="table table-sm">
            <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Number') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th class="money">{{ __('Discount') }}</th>
                <th class="money">{{ __('Total') }}</th>
                <th class="money">{{ __('Paid') }}</th>
                <th class="money">{{ __('Owed') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($purchases as $purchase)
                @php $returned = $purchase->returns->sum('total_amount'); @endphp
                <tr class="fw-semibold">
                    <td dir="ltr">{{ $purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }}</td>
                    <td dir="ltr">{{ $purchase->document_no }}</td>
                    <td>{{ $purchase->supplier->name }}</td>
                    <td class="money">{{ money($purchase->discount_amount, false) }}</td>
                    <td class="money">{{ money($purchase->grand_total, false) }}</td>
                    <td class="money">{{ money($purchase->amountPaid(), false) }}</td>
                    <td class="money">{{ money($purchase->amountDue(), false) }}</td>
                </tr>

                {{-- Section 1 rule 2: the unit price shown is the one that was
                     typed, which is what the batch cost. A discount on the
                     document never moves it. --}}
                @if($detailed)
                    @foreach($purchase->items as $item)
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
                            <td class="money">{{ money($item->quantity * $item->unit_price, false) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    @endforeach

                    @if($returned > 0)
                        <tr class="small">
                            <td></td>
                            <td colspan="2" class="ps-3">
                                {{ __('Returned') }}:
                                <span dir="ltr">{{ $purchase->returns->pluck('document_no')->implode(', ') }}</span>
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
                    {{ trans_choice('{1}:count purchase|[2,*]:count purchases', $purchases->count(), ['count' => number_format($purchases->count())]) }}
                </td>
                <td class="money">{{ money($totals['discount'], false) }}</td>
                <td class="money">{{ money($totals['bought'], false) }}</td>
                <td class="money">{{ money($totals['paid'], false) }}</td>
                <td class="money">{{ money($totals['bought'] - $totals['paid'], false) }}</td>
            </tr>
            @if($totals['returned'] > 0)
                <tr>
                    <td colspan="6">{{ __('Returned in this period') }}</td>
                    <td class="money">− {{ money($totals['returned'], false) }}</td>
                </tr>
            @endif
            </tfoot>
        </table>
    @endif
@endsection
