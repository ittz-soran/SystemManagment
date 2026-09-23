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
        @php($writtenIn = $sale->writtenIn())

        {{-- ⚠️ **ONE currency on a receipt, not two — Soran, 2026-09-19:
             "if system on dinar all receipts show on dinar and same for other
             currencies".**

             This is where the receipt parts company with the purchase document,
             which prints both figures and the rate (decision 1c). A supplier
             invoice is reconciled against paperwork in two currencies; a
             customer receipt is handed across a counter and has to say one
             number. The rate still comes off the DOCUMENT, never today's
             table — Sale::asWritten. --}}
        @php($say = fn (int $base, bool $mark = true) => $writtenIn
            ? $sale->asWritten($base).($mark ? ' '.$writtenIn->mark() : '')
            : money($base, $mark))

        <tbody>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                    {{ $item->product->name }}
                    <div class="small" dir="ltr">{{ $item->product->sku }}</div>

                    {{-- ⚠️ Read off the LINE, not the product: this is what was
                         promised on the day, and the product may have been
                         edited since. --}}
                    @if($item->warranty_days !== null)
                        <div class="small">
                            {{ __('Warranty') }}
                            {{ trans_choice('{0}Same day|{1}:count day|[2,*]:count days', $item->warranty_days, ['count' => $item->warranty_days]) }}
                            @php($ends = $item->warrantyEndsOn())
                            @if($ends)
                                <span dir="ltr">· {{ __('until :date', ['date' => $ends->format(setting('date_format', 'Y-m-d'))]) }}</span>
                            @endif
                        </div>
                    @endif
                </td>
                <td class="money">{{ qty($item->quantity, $item->product->unit) }}</td>
                <td class="money">{{ $say($item->unit_price, false) }}</td>
                <td class="money">{{ $say($item->lineTotal(), false) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="fw-bold">
            <td colspan="4" class="text-end">{{ __('Total') }}</td>
            <td class="money">{{ $say($sale->total_amount) }}</td>
        </tr>
        @if($sale->amountPaid() > 0)
            <tr>
                <td colspan="4" class="text-end">{{ __('Paid') }}</td>
                <td class="money">{{ $say($sale->amountPaid(), false) }}</td>
            </tr>
        @endif
        @if($sale->amountDue() > 0)
            <tr class="fw-bold">
                <td colspan="4" class="text-end">{{ __('Remaining') }}</td>
                <td class="money">{{ $say($sale->amountDue(), false) }}</td>
            </tr>
        @endif
        </tfoot>
    </table>

    {{-- The total written out. On paper this is the point of it: a digit can be
         changed with a pen and a sentence cannot, which is why invoices have
         carried the amount in words for as long as there have been invoices. --}}
    <div class="mt-2 fw-semibold">
        {{ App\Support\AmountInWords::for($sale->total_amount) }}
    </div>

    <div class="small mt-4">{{ __('Served by :name', ['name' => $sale->user->name]) }}</div>
@endsection
