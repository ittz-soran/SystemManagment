@extends('layouts.app')

@section('title', __('Sales history'))

@section('actions')
    @can('sales.create')
        <a href="{{ route('sales.create') }}" class="btn btn-primary">
            <i class="bi bi-cart-plus me-1"></i>{{ __('New sale') }}
        </a>
    @endcan
@endsection

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Invoice number') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="INV-">
            </div>
            <div class="col-md-3">
                <label for="customer_id" class="form-label small">{{ __('Customer') }}</label>
                <select id="customer_id" name="customer_id" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>
                            {{ $customer->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(['active' => __('Active'), 'partly_returned' => __('Partly returned'), 'returned' => __('Returned')] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
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
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('sales.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($sales->isEmpty())
        <div class="card">
            <x-empty-state icon="receipt"
                           :message="__('No sales yet. Create your first sale.')"
                           :action="Gate::allows('sales.create') ? route('sales.create') : null"
                           :action-label="__('New sale')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Invoice') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="money">{{ __('Total') }}</th>
                        <th class="money">{{ __('Due') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($sales as $sale)
                        <tr>
                            <td class="fw-medium" dir="ltr">{{ $sale->document_no }}</td>
                            <td dir="ltr">{{ $sale->sale_date->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td>{{ $sale->customer->name }}</td>
                            <td><x-status-badge :status="$sale->status" /></td>
                            <td class="money">{{ money($sale->total_amount, false) }}</td>
                            <td class="money {{ $sale->amountDue() > 0 ? 'text-danger' : 'text-secondary' }}">
                                {{ money($sale->amountDue(), false) }}
                            </td>
                            <td class="text-end">
                                <x-row-actions :view="route('sales.show', $sale)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $sales->links() }}</div>
    @endif
@endsection
