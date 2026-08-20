@extends('layouts.print')

@section('title', $return->document_no)
@section('doc-title', __('Sale return'))
@section('doc-number', $return->document_no)
@section('doc-date', $return->return_date->format(setting('date_format', 'Y-m-d')))

@section('content')
    <div class="mb-3">
        <div class="small text-uppercase">{{ __('Customer') }}</div>
        <div class="fw-semibold">{{ $return->customer->displayName() }}</div>
        <div class="small">
            {{ __('Against invoice :document', ['document' => $return->sale->document_no]) }}
        </div>
    </div>

    <table class="table table-sm">
        <thead>
        <tr>
            <th>#</th>
            <th>{{ __('Product') }}</th>
            <th class="money">{{ __('Quantity') }}</th>
            <th class="money">{{ __('Unit price') }}</th>
            <th class="money">{{ __('Refund') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($return->items as $item)
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
            <td colspan="4" class="text-end">{{ __('Total refund') }}</td>
            <td class="money">{{ money($return->total_amount) }}</td>
        </tr>
        </tfoot>
    </table>

    @if($return->reason)
        <div class="small mt-3">{{ __('Reason') }}: {{ $return->reason }}</div>
    @endif
    <div class="small mt-4">{{ __('Handled by :name', ['name' => $return->user->name]) }}</div>
@endsection
