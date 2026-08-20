@extends('layouts.app')

@section('title', __('Payments'))

@section('content')
    {{-- Section 4: reports read the direction, so cash in and cash out stay
         separate and legible. --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">{{ __('Money in') }}</div>
                    <div class="fs-4 fw-semibold money text-success">{{ money($totalIn) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">{{ __('Money out') }}</div>
                    <div class="fs-4 fw-semibold money text-danger">{{ money($totalOut) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">{{ __('Net') }}</div>
                    <div class="fs-4 fw-semibold money">{{ money($totalIn - $totalOut) }}</div>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label for="search" class="form-label small">{{ __('Document') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="PAY-">
            </div>
            <div class="col-md-2">
                <label for="direction" class="form-label small">{{ __('Direction') }}</label>
                <select id="direction" name="direction" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="in" @selected(request('direction') === 'in')>{{ __('In') }}</option>
                    <option value="out" @selected(request('direction') === 'out')>{{ __('Out') }}</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="method" class="form-label small">{{ __('Method') }}</label>
                <select id="method" name="method" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(['cash' => __('Cash'), 'bank' => __('Bank'), 'transfer' => __('Transfer')] as $value => $label)
                        <option value="{{ $value }}" @selected(request('method') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('payments.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($payments->isEmpty())
        <div class="card">
            <x-empty-state icon="cash-coin" :message="__('No payments recorded yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Against') }}</th>
                        <th>{{ __('Method') }}</th>
                        <th>{{ __('By') }}</th>
                        <th class="money">{{ __('Amount') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($payments as $payment)
                        <tr>
                            <td class="fw-medium" dir="ltr">{{ $payment->document_no }}</td>
                            <td dir="ltr">{{ $payment->paid_at->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td class="small">
                                <span class="badge text-bg-light">{{ Str::headline($payment->payable_type) }}</span>
                                <span class="text-secondary">#{{ $payment->payable_id }}</span>
                            </td>
                            <td>{{ Str::headline($payment->payment_method) }}</td>
                            <td class="small text-secondary">{{ $payment->user->name }}</td>
                            <td class="money fw-semibold {{ $payment->direction === 'in' ? 'text-success' : 'text-danger' }}">
                                {{ $payment->direction === 'in' ? '+' : '−' }}{{ money($payment->amount, false) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $payments->links() }}</div>
    @endif
@endsection
