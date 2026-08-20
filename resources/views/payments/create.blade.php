@extends('layouts.app')

@section('title', __('Record a payment'))
@section('subheading', $payable->document_no.' · '.$context['party'])

@section('content')
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('payments.store') }}" method="POST" data-guard-submit>
                        @csrf
                        <input type="hidden" name="payable_type" value="{{ $payableType }}">
                        <input type="hidden" name="payable_id" value="{{ $payable->id }}">

                        <div class="mb-3">
                            <label for="amount" class="form-label">{{ __('Amount') }}</label>
                            <div class="input-group">
                                <input id="amount" type="number" step="1" min="1" name="amount" dir="ltr"
                                       class="form-control text-end @error('amount') is-invalid @enderror"
                                       value="{{ old('amount', $context['due'] > 0 ? $context['due'] : $context['total']) }}"
                                       required autofocus>
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
                                <option value="in" @selected(old('direction', $context['direction']) === 'in')>
                                    {{ __('In — money arriving in the till') }}
                                </option>
                                <option value="out" @selected(old('direction', $context['direction']) === 'out')>
                                    {{ __('Out — money leaving the till') }}
                                </option>
                            </select>
                            <div class="form-text">{{ $context['hint'] }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="payment_method" class="form-label">{{ __('Method') }}</label>
                            <select id="payment_method" name="payment_method" class="form-select">
                                <option value="cash">{{ __('Cash') }}</option>
                                <option value="bank">{{ __('Bank') }}</option>
                                <option value="transfer">{{ __('Transfer') }}</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="paid_at" class="form-label">{{ __('Date') }}</label>
                            <input id="paid_at" type="date" name="paid_at" class="form-control"
                                   value="{{ old('paid_at', today()->toDateString()) }}" required>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">{{ __('Notes') }}</label>
                            <input id="notes" name="notes" class="form-control" value="{{ old('notes') }}">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"
                                    data-submitting-text="{{ __('Saving…') }}">{{ __('Save payment') }}</button>
                            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">{{ __('This document') }}</div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-secondary">{{ __('Total') }}</span>
                        <span class="money">{{ money($context['total'], false) }}</span>
                    </li>
                    @if($context['due'] > 0 || $context['paid'] > 0)
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-secondary">{{ __('Already paid') }}</span>
                            <span class="money">{{ money($context['paid'], false) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between fw-semibold">
                            <span>{{ __('Remaining') }}</span>
                            <span class="money">{{ money($context['due'], false) }}</span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
@endsection
