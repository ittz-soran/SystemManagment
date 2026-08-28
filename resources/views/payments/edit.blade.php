@extends('layouts.app')

@section('title', $payment->document_no)
@section('heading', __('Edit :document', ['document' => $payment->document_no]))
@section('subheading')
    @if($payable)
        <x-document-link :document="$payable" :kind="false" />
        @if($party) · {{ $party->displayName() ?? $party->name }} @endif
    @endif
@endsection

@section('back')
    <x-back-link :to="route('payments.show', $payment)" :label="$payment->document_no"
                 permission="payments.view" />
@endsection

@section('content')
    {{-- Section 8: an edit reverses what the payment did to the ledger and
         posts it again with the new figures, so the balance ends where it would
         have if the figure had been right the first time. The document it is
         against is not part of it — a payment pointed somewhere else is a
         different payment. --}}
    <div class="row">
        <div class="col-lg-6">
            <form action="{{ route('payments.update', $payment) }}" method="POST" class="card" data-guard-submit>
                @csrf
                @method('PUT')

                <div class="card-header">{{ __('Payment') }}</div>

                <div class="card-body">
                    @if($payable)
                        <div class="mb-3">
                            <div class="form-label">{{ __('Against') }}</div>
                            <div class="form-control-plaintext">
                                <x-document-link :document="$payable" :kind="false" />
                            </div>
                        </div>
                    @endif

                    <div class="mb-3">
                        <label for="amount" class="form-label">{{ __('Amount') }}</label>
                        <div class="input-group">
                            <input id="amount" type="number" step="1" min="1" name="amount" dir="ltr"
                                   class="form-control text-end @error('amount') is-invalid @enderror"
                                   data-numpad="{{ __('Amount') }}" data-numpad-min="1"
                                   value="{{ old('amount', $payment->amount) }}" required>
                            <span class="input-group-text">{{ __('IQD') }}</span>
                            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- Section 4: the amount is ALWAYS positive; the
                             direction says which way it moved. --}}
                        <div class="form-text">{{ __('Always a positive number. The direction below says which way it moved.') }}</div>
                    </div>

                    <div class="mb-3">
                        <label for="direction" class="form-label">{{ __('Direction') }}</label>
                        <select id="direction" name="direction" class="form-select">
                            <option value="in" @selected(old('direction', $payment->direction) === 'in')>
                                {{ __('In — money arriving in the till') }}
                            </option>
                            <option value="out" @selected(old('direction', $payment->direction) === 'out')>
                                {{ __('Out — money leaving the till') }}
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="payment_method" class="form-label">{{ __('Method') }}</label>
                        <select id="payment_method" name="payment_method" class="form-select">
                            @foreach(['cash' => __('Cash'), 'bank' => __('Bank'), 'transfer' => __('Transfer')] as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_method', $payment->payment_method) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="paid_at" class="form-label">{{ __('Date') }}</label>
                        <input id="paid_at" type="date" name="paid_at" class="form-control" required
                               value="{{ old('paid_at', $payment->paid_at->toDateString()) }}">
                    </div>

                    <div>
                        <label for="notes" class="form-label">{{ __('Notes') }}</label>
                        <input id="notes" name="notes" class="form-control"
                               value="{{ old('notes', $payment->notes) }}">
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('payments.show', $payment) }}"
                       class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('Save payment') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
