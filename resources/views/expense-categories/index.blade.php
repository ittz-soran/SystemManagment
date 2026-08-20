@extends('layouts.app')

@section('title', __('Expense categories'))
@section('subheading', __('A managed list, so the expense report groups cleanly'))

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#category-modal">
        <i class="bi bi-plus-lg me-1"></i>{{ __('New category') }}
    </button>
@endsection

@section('content')
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="money">{{ __('Expenses') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($categories as $category)
                    <tr class="{{ $category->is_active ? '' : 'opacity-50' }}">
                        <td class="fw-medium">{{ $category->name }}</td>
                        <td>
                            <span class="badge text-bg-{{ $category->is_active ? 'success' : 'secondary' }}">
                                {{ $category->is_active ? __('Active') : __('Hidden from new entries') }}
                            </span>
                        </td>
                        <td class="money text-secondary">{{ number_format($category->expenses_count) }}</td>
                        <td class="text-end">
                            <form action="{{ route('expense-categories.update', $category) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="name" value="{{ $category->name }}">
                                <input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}">
                                <button class="btn btn-sm btn-outline-secondary">
                                    {{ $category->is_active ? __('Deactivate') : __('Reactivate') }}
                                </button>
                            </form>

                            <x-row-actions
                                :delete="route('expense-categories.destroy', $category)"
                                :delete-label="__('Delete :name? Categories with expenses are deactivated instead.', ['name' => $category->name])" />
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $categories->links() }}</div>

    <div class="modal fade" id="category-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" action="{{ route('expense-categories.store') }}" method="POST" data-guard-submit>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('New category') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <label for="category-name" class="form-label">{{ __('Name') }}</label>
                    <input id="category-name" name="name" class="form-control" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Save category') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
