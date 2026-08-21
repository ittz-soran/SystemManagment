@extends('layouts.app')

@section('title', __('Purchase history'))

@section('actions')
    @can('purchases.create')
        <a href="{{ route('purchases.create') }}" class="btn btn-primary">
            <i class="bi bi-bag-plus me-1"></i>{{ __('New purchase') }}
        </a>
    @endcan
@endsection

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Document or supplier invoice') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="PUR-">
            </div>
            <div class="col-md-3">
                <label for="supplier_id" class="form-label small">{{ __('Supplier') }}</label>
                <select id="supplier_id" name="supplier_id" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
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
                <a href="{{ route('purchases.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($purchases->isEmpty())
        <div class="card">
            <x-empty-state icon="journal-text"
                           :message="__('No purchases yet. Record your first purchase.')"
                           :action="Gate::allows('purchases.create') ? route('purchases.create') : null"
                           :action-label="__('New purchase')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        @can('purchases.delete')
                            <th style="width: 2.5rem">
                                <input type="checkbox" class="form-check-input" id="bulk-select-all"
                                       aria-label="{{ __('Select all') }}">
                            </th>
                        @endcan
                        <th>{{ __('Document') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Supplier') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="money">{{ __('Grand total') }}</th>
                        <th class="money">{{ __('Due') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($purchases as $purchase)
                        <tr>
                            @can('purchases.delete')
                                <td>
                                    <input type="checkbox" class="form-check-input" data-bulk-id="{{ $purchase->id }}"
                                           aria-label="{{ __('Select :document', ['document' => $purchase->document_no]) }}">
                                </td>
                            @endcan
                            <td class="fw-medium" dir="ltr">
                                {{ $purchase->document_no }}
                                @if($purchase->supplier_invoice_no)
                                    <div class="small text-secondary">{{ $purchase->supplier_invoice_no }}</div>
                                @endif
                            </td>
                            <td dir="ltr">{{ $purchase->purchase_date->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td>{{ $purchase->supplier->name }}</td>
                            <td><x-status-badge :status="$purchase->status" /></td>
                            <td class="money">{{ money($purchase->grand_total, false) }}</td>
                            <td class="money {{ $purchase->amountDue() > 0 ? 'text-danger' : 'text-secondary' }}">
                                {{ money($purchase->amountDue(), false) }}
                            </td>
                            <td class="text-end">
                                <x-row-actions :view="route('purchases.show', $purchase)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $purchases->links() }}</div>

        @can('purchases.delete')
            <x-bulk-delete :action="route('purchases.bulk-destroy')"
                           :confirm="__('Delete the selected purchases? Their batches are removed from stock.')" />
        @endcan
    @endif
@endsection
