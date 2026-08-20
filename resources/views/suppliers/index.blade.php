@extends('layouts.app')

@section('title', __('Suppliers'))

@section('actions')
    @can('suppliers.create')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplier-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New supplier') }}
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
                <a href="{{ route('suppliers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($suppliers->isEmpty())
        <div class="card">
            <x-empty-state icon="people" :message="__('No suppliers yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Phone') }}</th>
                        <th class="money">{{ __('The shop owes') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($suppliers as $supplier)
                        <tr class="{{ $supplier->is_active ? '' : 'opacity-50' }}">
                            <td>
                                <a href="{{ route('suppliers.show', $supplier) }}" class="text-decoration-none fw-medium">
                                    {{ $supplier->name }}
                                </a>
                                @if($supplier->is_system ?? false)
                                    <span class="badge text-bg-light">{{ __('System') }}</span>
                                @endif
                            </td>
                            <td dir="ltr">{{ $supplier->phone ?: '—' }}</td>
                            <td class="money {{ $supplier->balance > 0 ? 'fw-semibold' : 'text-secondary' }}">
                                {{ money($supplier->balance, false) }}
                            </td>
                            <td class="text-end">
                                <x-row-actions
                                    :view="route('suppliers.show', $supplier)"
                                    :delete="Gate::allows('suppliers.delete') && ! ($supplier->is_system ?? false) ? route('suppliers.destroy', $supplier) : null"
                                    :delete-label="__('Delete :name?', ['name' => $supplier->name])" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $suppliers->links() }}</div>
    @endif

    @can('suppliers.create')
        <div class="modal fade" id="supplier-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" action="{{ route('suppliers.store') }}" method="POST" data-guard-submit>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New supplier') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="supplier-name" class="form-label">{{ __('Name') }}</label>
                            <input id="supplier-name" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="supplier-phone" class="form-label">{{ __('Phone') }}</label>
                            <input id="supplier-phone" name="phone" class="form-control" dir="ltr">
                        </div>
                        <div>
                            <label for="supplier-address" class="form-label">{{ __('Address') }}</label>
                            <input id="supplier-address" name="address" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save supplier') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
