@extends('layouts.app')

@section('title', $payment->document_no)
@section('subheading')
    {{ $payment->paid_at->format(setting('date_format', 'Y-m-d')) }}
    · {{ Str::headline($payment->payment_method) }}
@endsection

@section('actions')
    @can('payments.edit')
        <a href="{{ route('payments.edit', $payment) }}" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>{{ __('Edit') }}
        </a>
    @endcan

    @can('payments.delete')
        {{-- Section 8b: removing the payment puts the debt back. --}}
        <form action="{{ route('payments.destroy', $payment) }}" method="POST"
              onsubmit="return confirm(@js(__('Delete :document? :amount goes back onto what is owed.', [
                  'document' => $payment->document_no,
                  'amount' => money($payment->amount),
              ])))">
            @csrf
            @method('DELETE')
            <x-return-to />
            <button class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>{{ __('Delete payment') }}
            </button>
        </form>
    @endcan
@endsection

@section('back')
    <x-back-link :to="route('payments.index')" :label="__('Payments')" remember="payments" permission="payments.view" />
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">{{ __('Payment') }}</div>
                <div class="card-body">
                    {{-- Section 4: the amount is always positive and the direction
                         carries the sign, so the direction is what to read first. --}}
                    <div class="d-flex align-items-baseline justify-content-between mb-3">
                        <span class="text-secondary">
                            {{ $payment->direction === App\Models\Payment::DIRECTION_IN
                                ? __('Money into the till')
                                : __('Money out of the till') }}
                        </span>
                        <span class="fs-3 fw-semibold money {{ $payment->direction === App\Models\Payment::DIRECTION_IN ? 'text-success' : 'text-danger' }}">
                            {{ $payment->direction === App\Models\Payment::DIRECTION_IN ? '+' : '−' }}{{ money($payment->amount) }}
                        </span>
                    </div>

                    <dl class="row mb-0 small">
                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Against') }}</dt>
                        <dd class="col-sm-8">
                            <x-document-link :document="$payment->payable"
                                             :type="$payment->payable_type"
                                             :id="$payment->payable_id" />
                        </dd>

                        @if($party)
                            <dt class="col-sm-4 text-secondary fw-normal">
                                {{ $party instanceof App\Models\Customer ? __('Customer') : __('Supplier') }}
                            </dt>
                            <dd class="col-sm-8">
                                <x-document-link :document="$party" :kind="false" />
                            </dd>
                        @endif

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Method') }}</dt>
                        <dd class="col-sm-8">{{ Str::headline($payment->payment_method) }}</dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Date') }}</dt>
                        <dd class="col-sm-8" dir="ltr">
                            {{ $payment->paid_at->format(setting('date_format', 'Y-m-d')) }}
                        </dd>

                        <dt class="col-sm-4 text-secondary fw-normal">{{ __('Recorded by') }}</dt>
                        <dd class="col-sm-8">{{ $payment->user->name }}</dd>

                        @if($payment->notes)
                            <dt class="col-sm-4 text-secondary fw-normal">{{ __('Notes') }}</dt>
                            <dd class="col-sm-8 mb-0">{{ $payment->notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- What this payment left behind on the document it was against.
                 A payment is only ever read to answer "and what is still due?"
                 A return has no running balance of its own — it is settled in
                 one movement — so only the two documents that carry one show it. --}}
            @if($payment->payable)
                <div class="card">
                    <div class="card-header">{{ __('The document now') }}</div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-secondary">{{ __('Total') }}</span>
                            <span class="money">{{ money($payment->payable->total_amount, false) }}</span>
                        </li>
                        @if(method_exists($payment->payable, 'amountDue'))
                            <li class="list-group-item d-flex justify-content-between fw-semibold">
                                <span>{{ __('Due') }}</span>
                                <span class="money">{{ money($payment->payable->amountDue()) }}</span>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>
    </div>
<x-record-history :for="$payment" />
@endsection
