@extends('layouts.print')

@section('title', __('Repair ticket'))
@section('doc-title', __('Repair ticket').' · '.$repair->document_no)
@section('doc-date', $repair->received_at->format(setting('date_format', 'Y-m-d')))

@section('content')
    {{-- ⚠️ This is the half of the record the customer walks away with, and
         brings back to collect the phone. Everything they agreed to is on it:
         what was wrong, what it will cost, who is doing it, and what is
         guaranteed for how long. --}}

    <div class="row mb-3">
        <div class="col-6">
            <div class="small text-uppercase">{{ __('Customer') }}</div>
            <div class="fw-semibold">{{ $repair->customer->name }}</div>
            @if($repair->customer->phone)
                <div dir="ltr">{{ $repair->customer->phone }}</div>
            @endif
        </div>

        <div class="col-6">
            <div class="small text-uppercase">{{ __('Who is doing it') }}</div>
            @if($repair->technician)
                <div class="fw-semibold">{{ $repair->technician->name }}</div>
                @if($repair->technician->phone)
                    <div dir="ltr">{{ $repair->technician->phone }}</div>
                @endif
            @else
                <div>—</div>
            @endif
        </div>
    </div>

    <table class="table table-sm mb-3">
        <tbody>
        <tr>
            <th style="width: 10rem">{{ __('Device') }}</th>
            <td>{{ $repair->device }}</td>
        </tr>
        @if($repair->identifier)
            <tr>
                <th>{{ __('IMEI or serial') }}</th>
                <td dir="ltr">{{ $repair->identifier }}</td>
            </tr>
        @endif
        <tr>
            <th>{{ __('What is wrong') }}</th>
            <td>{{ $repair->fault }}</td>
        </tr>
        @if($repair->condition_note)
            <tr>
                <th>{{ __('How it looked when it came in') }}</th>
                <td>{{ $repair->condition_note }}</td>
            </tr>
        @endif
        @if($repair->promised_for)
            <tr>
                <th>{{ __('Ready by') }}</th>
                <td dir="ltr">{{ $repair->promised_for->format(setting('date_format', 'Y-m-d')) }}</td>
            </tr>
        @endif
        </tbody>
    </table>

    <div class="small text-uppercase mb-1">{{ __('What was agreed') }}</div>

    <table class="table table-sm">
        <thead>
        <tr>
            <th>{{ __('Part or work') }}</th>
            <th>{{ __('Warranty') }}</th>
            <th class="money">{{ __('Qty') }}</th>
            <th class="money">{{ __('Price') }}</th>
            <th class="money">{{ __('Total') }}</th>
        </tr>
        </thead>

        <tbody>
        @foreach($repair->items as $item)
            <tr>
                <td>{{ $item->product->name }}</td>
                <td>
                    @if($item->warranty_days === null)
                        —
                    @else
                        {{ trans_choice('{0}Same day|{1}:count day|[2,*]:count days', $item->warranty_days, ['count' => $item->warranty_days]) }}
                    @endif
                </td>
                <td class="money">{{ number_format($item->quantity) }}</td>
                <td class="money">{{ money($item->unit_price, false) }}</td>
                <td class="money">{{ money($item->lineTotal(), false) }}</td>
            </tr>
        @endforeach
        </tbody>

        <tfoot>
        <tr>
            <th colspan="4">{{ __('Agreed total') }}</th>
            <th class="money">{{ money($repair->accepted_total ?? $repair->total(), false) }}</th>
        </tr>
        </tfoot>
    </table>

    {{-- ⚠️ Warranty runs from the day the phone is collected, not from today.
         Printed on the ticket so the promise is the customer's to hold, and
         cannot be argued about later. --}}
    @if($repair->items->contains(fn ($item) => $item->warranty_days !== null))
        <p class="small mt-3 mb-0">
            <strong>{{ __('Warranty') }}:</strong>
            {{ __('counted from the day you collect the phone, and covers only the work and parts listed above.') }}
        </p>
    @endif

    <p class="small mt-3 mb-0">
        {{ __('Please bring this ticket back to collect the phone.') }}
        {{ __('If the price has to change, we will tell you before any further work.') }}
    </p>
@endsection
