@extends('layouts.app')

@section('title', __('Categories'))

@section('actions')
    @can('categories.create')
        {{-- Section 9b: modal for a single short form. --}}
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#category-modal">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New category') }}
        </button>
    @endcan
@endsection

@section('content')
    @if($categories->isEmpty())
        <div class="card">
            <x-empty-state icon="tags" :message="__('No categories yet. Every product needs one.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Parent') }}</th>
                        <th class="money">{{ __('Products') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($categories as $category)
                        <tr>
                            <td class="fw-medium">{{ $category->name }}</td>
                            <td class="text-secondary">{{ $category->parent?->name ?? '—' }}</td>
                            <td class="money">
                                <a href="{{ route('products.index', ['categories' => [$category->id]]) }}"
                                   class="text-decoration-none">{{ number_format($category->products_count) }}</a>
                            </td>
                            <td class="text-end">
                                <x-row-actions
                                    :delete="Gate::allows('categories.delete') ? route('categories.destroy', $category) : null"
                                    :delete-label="__('Delete :name?', ['name' => $category->name])" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $categories->links() }}</div>
    @endif

    @can('categories.create')
        <div class="modal fade" id="category-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" action="{{ route('categories.store') }}" method="POST" data-guard-submit>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('New category') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category-name" class="form-label">{{ __('Name') }}</label>
                            <input id="category-name" name="name" class="form-control" required>
                        </div>
                        <div>
                            <label for="category-parent" class="form-label">{{ __('Parent category') }}</label>
                            <select id="category-parent" name="parent_id" class="form-select">
                                <option value="">{{ __('None — top level') }}</option>
                                @foreach($parents as $parent)
                                    <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save category') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
