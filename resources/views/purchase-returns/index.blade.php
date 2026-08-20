@extends('layouts.app')

@section('title', __('Purchase returns'))

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small">{{ __('Document number') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="PRT-">
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small">{{ __('To') }}</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('purchase-returns.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($returns->isEmpty())
        <div class="card">
            <x-empty-state icon="arrow-return-left" :message="__('No returns yet. Start one from a document.')" />
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
                        <th>{{ __('Supplier') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th class="money">{{ __('Total') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($returns as $return)
                        <tr>
                            <td class="fw-medium" dir="ltr">{{ $return->document_no }}</td>
                            <td dir="ltr">{{ $return->return_date->format(setting('date_format', 'Y-m-d')) }}</td>
                            <td dir="ltr">
                                <a href="{{ route('purchases.show', $return->purchase) }}" class="text-decoration-none">
                                    {{ $return->purchase->document_no }}
                                </a>
                            </td>
                            <td>{{ $return->supplier->name }}</td>
                            <td class="text-secondary small">{{ $return->reason ?: '—' }}</td>
                            <td class="money">{{ money($return->total_amount, false) }}</td>
                            <td class="text-end">
                                <x-row-actions :view="route('purchase-returns.show', $return)" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $returns->links() }}</div>
    @endif
@endsection
