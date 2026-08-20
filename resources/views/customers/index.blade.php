@extends('layouts.app')

@section('title', __('Customers'))

@section('actions')
    @can('customers.create')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customer-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New customer') }}
        </button>
    @endcan
@endsection

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label small">{{ __('Search') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="{{ __('Name') }}">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('customers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($customers->isEmpty())
        <div class="card">
            <x-empty-state icon="people" :message="__('No customers yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Phone') }}</th>
                        <th class="money">{{ __('Owes the shop') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($customers as $customer)
                        <tr class="{{ $customer->is_active ? '' : 'opacity-50' }}">
                            <td>
                                <a href="{{ route('customers.show', $customer) }}" class="text-decoration-none fw-medium">
                                    {{ $customer->displayName() }}
                                </a>
                                @if($customer->is_system ?? false)
                                    <span class="badge text-bg-light">{{ __('System') }}</span>
                                @endif
                            </td>
                            <td dir="ltr">{{ $customer->phone ?: '—' }}</td>
                            <td class="money {{ $customer->balance > 0 ? 'fw-semibold' : 'text-secondary' }}">
                                {{ money($customer->balance, false) }}
                            </td>
                            <td class="text-end">
                                <x-row-actions
                                    :view="route('customers.show', $customer)"
                                    :delete="Gate::allows('customers.delete') && ! ($customer->is_system ?? false) ? route('customers.destroy', $customer) : null"
                                    :delete-label="__('Delete :name?', ['name' => $customer->displayName()])" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $customers->links() }}</div>
    @endif

    @can('customers.create')
        <div class="modal fade" id="customer-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" action="{{ route('customers.store') }}" method="POST" data-guard-submit>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New customer') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer-name" class="form-label">{{ __('Name') }}</label>
                            <input id="customer-name" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="customer-phone" class="form-label">{{ __('Phone') }}</label>
                            <input id="customer-phone" name="phone" class="form-control" dir="ltr">
                        </div>
                        <div>
                            <label for="customer-address" class="form-label">{{ __('Address') }}</label>
                            <input id="customer-address" name="address" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save customer') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
