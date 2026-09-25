@extends('layouts.print')

@section('title', $swap->document_no)
@section('doc-title', __('Swap'))
@section('doc-number', $swap->document_no)
@section('doc-date', $swap->swapped_at->format(setting('date_format', 'Y-m-d')))

{{--
    The same sheet as SRT and PRT — Soran, 2026-09-25: *"make all three
    purchase-returns, sale-returns, swaps have same designs or same like one"*.

    ⚠️ **The sentence about the invoice is on the paper, not only on the
    screen.** This is the one document in the shop the customer may be handed
    that changes nothing they were charged, and a piece of paper with a
    document number on it reads as a bill unless it says otherwise.
--}}
@section('content')
    <div class="mb-3">
        <div class="small text-uppercase">{{ __('Customer') }}</div>
        <div class="fw-semibold">{{ $swap->sale?->customer?->displayName() ?? '—' }}</div>
        @if($swap->sale)
            <div class="small">
                {{ __('Against invoice :document', ['document' => $swap->sale->document_no]) }}
            </div>
        @endif
    </div>

    <table class="table table-sm">
        <thead>
        <tr>
            <th>#</th>
            <th>{{ __('Product') }}</th>
            <th class="money">{{ __('Quantity') }}</th>
            <th class="money">{{ __('The replacement cost') }}</th>
            <th class="money">{{ __('Given back for the faulty one') }}</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>1</td>
            <td>
                {{ $swap->product->name }}
                <div class="small" dir="ltr">{{ $swap->product->sku }}</div>
            </td>
            <td class="money">{{ qty($swap->quantity, $swap->product->unit) }}</td>
            <td class="money">{{ money($swap->replacement_cost, false) }}</td>
            <td class="money">−{{ money($swap->faulty_cost, false) }}</td>
        </tr>
        </tbody>
        <tfoot>
        <tr class="fw-bold">
            {{-- Read rather than printed: "Out of pocket: −4,000" would say the
                 opposite of what it means. --}}
            <td colspan="4" class="text-end">
                {{ $swap->cost() < 0 ? __('Ahead by') : __('Out of pocket') }}
            </td>
            <td class="money">{{ money(abs($swap->cost())) }}</td>
        </tr>
        </tfoot>
    </table>

    <div class="small mt-3">
        {{ __('The invoice was not changed. The customer bought it and still owns it — what changed is which unit they have.') }}
    </div>

    @if($swap->purchaseReturn)
        <div class="small mt-2">
            {{ __('Back to :supplier', ['supplier' => $swap->purchaseReturn->purchase?->supplier?->name ?? __('the supplier')]) }}
            · {{ $swap->purchaseReturn->document_no }}
        </div>
    @endif

    @if($swap->note)
        <div class="small mt-2">{{ __('Note') }}: {{ $swap->note }}</div>
    @endif

    <div class="small mt-4">{{ __('Handled by :name', ['name' => $swap->user?->name ?? '—']) }}</div>
@endsection
