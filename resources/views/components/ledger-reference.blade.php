{{--
    The Reference cell of a supplier or customer statement.

    It used to print the row's free-text note and nothing else, so auditing a
    balance meant recognising "PUR-00004" and going to find it by hand. The row
    stores the document that moved the balance, so it is shown as a link.

    The note is kept underneath whenever it says more than the document number
    already does — "Reversal of PUR-00004", "Cash refund for SRT-00002" and a
    cashier's own note on a payment are the reason a reader opens this screen.
--}}
@props(['transaction'])

@php
    $linker = \App\Support\DocumentLink::class;

    $label = $linker::label(
        $transaction->reference,
        $transaction->reference_type,
        $transaction->reference_id,
    );

    $note = filled($transaction->notes) && $transaction->notes !== $label
        ? $transaction->notes
        : null;
@endphp

{{-- The Type column beside this one already names the kind, so it is repeated
     only when the reference is a different one — as a refund against a purchase
     is. --}}
@if($transaction->reference_id)
    <x-document-link :document="$transaction->reference"
                     :type="$transaction->reference_type"
                     :id="$transaction->reference_id"
                     :kind="$transaction->reference_type !== $transaction->type" />
@endif

@if($note)
    <div class="text-secondary" @if($transaction->reference_id) style="margin-top: .1rem" @endif>{{ $note }}</div>
@endif

@if(! $transaction->reference_id && ! $note)
    <span class="text-secondary">—</span>
@endif
