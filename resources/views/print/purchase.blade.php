@extends('layouts.print')

@section('title', $purchase->document_no)
@section('doc-title', __('Purchase'))
@section('doc-number', $purchase->document_no)
@section('doc-date', $purchase->purchase_date->format(setting('date_format', 'Y-m-d')))

@section('content')
    <div class="mb-3">
        <div class="small text-uppercase">{{ __('Supplier') }}</div>
        <div class="fw-semibold">{{ $purchase->supplier->name }}</div>
        @if($purchase->supplier->phone)
            <div class="small" dir="ltr">{{ $purchase->supplier->phone }}</div>
        @endif
        @if($purchase->supplier_invoice_no)
            <div class="small">
                {{ __("Supplier's invoice") }}: <span dir="ltr">{{ $purchase->supplier_invoice_no }}</span>
            </div>
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
        @foreach($purchase->items as $item)
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
        <tr>
            <td colspan="4" class="text-end">{{ __('Subtotal') }}</td>
            <td class="money">{{ money($purchase->total_amount, false) }}</td>
        </tr>
        @if($purchase->discount_amount !== 0)
            <tr>
                <td colspan="4" class="text-end">{{ __('Discount') }}</td>
                <td class="money">−{{ money($purchase->discount_amount, false) }}</td>
            </tr>
        @endif
        <tr class="fw-bold">
            <td colspan="4" class="text-end">{{ __('Grand total') }}</td>
            <td class="money">{{ money($purchase->grand_total) }}</td>
        </tr>
        @if($purchase->amountDue() > 0)
            <tr class="fw-bold">
                <td colspan="4" class="text-end">{{ __('Remaining') }}</td>
                <td class="money">{{ money($purchase->amountDue(), false) }}</td>
            </tr>
        @endif
        </tfoot>
    </table>

    <div class="small mt-4">{{ __('Recorded by :name', ['name' => $purchase->user->name]) }}</div>
@endsection
