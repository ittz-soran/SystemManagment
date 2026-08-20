@extends('layouts.print')

@section('title', $sale->document_no)
@section('doc-title', __('Invoice'))
@section('doc-number', $sale->document_no)
@section('doc-date', $sale->sale_date->format(setting('date_format', 'Y-m-d')))

@section('content')
    <div class="mb-3">
        <div class="small text-uppercase">{{ __('Customer') }}</div>
        <div class="fw-semibold">{{ $sale->customer->displayName() }}</div>
        @if($sale->customer->phone)
            <div class="small" dir="ltr">{{ $sale->customer->phone }}</div>
        @endif
        @if($sale->customer->address)
            <div class="small">{{ $sale->customer->address }}</div>
        @endif
    </div>

    <table class="table table-sm">
        <thead>
        <tr>
            <th>#</th>
            <th>{{ __('Product') }}</th>
            <th class="money">{{ __('Quantity') }}</th>
            <th class="money">{{ __('Unit price') }}</th>
            <th class="money">{{ __('Total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                    {{ $item->product->name }}
                    <div class="small" dir="ltr">{{ $item->product->sku }}</div>
                </td>
                <td class="money">{{ number_format($item->quantity) }}</td>
                <td class="money">{{ money($item->unit_price, false) }}</td>
                <td class="money">{{ money($item->lineTotal(), false) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="fw-bold">
            <td colspan="4" class="text-end">{{ __('Total') }}</td>
            <td class="money">{{ money($sale->total_amount) }}</td>
        </tr>
        @if($sale->amountPaid() > 0)
            <tr>
                <td colspan="4" class="text-end">{{ __('Paid') }}</td>
                <td class="money">{{ money($sale->amountPaid(), false) }}</td>
            </tr>
        @endif
        @if($sale->amountDue() > 0)
            <tr class="fw-bold">
                <td colspan="4" class="text-end">{{ __('Remaining') }}</td>
                <td class="money">{{ money($sale->amountDue(), false) }}</td>
            </tr>
        @endif
        </tfoot>
    </table>

    <div class="small mt-4">{{ __('Served by :name', ['name' => $sale->user->name]) }}</div>
@endsection
