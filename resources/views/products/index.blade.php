@extends('layouts.app')

@section('title', __('Products'))

@section('actions')
    @can('products.create')
        <a href="{{ route('products.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New product') }}
        </a>
    @endcan
@endsection

{{-- Arrived from a category, so there is somewhere to go back to. The script
     turns this into the browser's own Back, which is where the reader came
     from whether that was the category list or anywhere else. --}}
@if(request()->filled('categories'))
    @section('back')
        <x-back-link :to="route('categories.index')" :label="__('Categories')"
                     remember="categories" permission="categories.view" />
    @endsection
@endif

@section('content')
    {{-- Three figures worth knowing before reading the table. The middle one is
         the one that needs acting on, so it is a filter as well: pressing it
         reloads the list showing only those rows. --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <span class="stat-tile-label">{{ __('Products') }}</span>
                <span class="stat-tile-value">{{ number_format($totalProducts) }}</span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <a class="stat-tile {{ request()->boolean('low_stock') ? 'is-on' : '' }}"
               href="{{ request()->boolean('low_stock')
                   ? route('products.index', request()->except(['low_stock', 'page']))
                   : route('products.index', [...request()->except('page'), 'low_stock' => 1]) }}">
                <span class="stat-tile-label">
                    <i class="bi bi-exclamation-triangle text-warning"></i>
                    {{ __('Low stock') }}
                </span>
                <span class="stat-tile-value {{ $lowStockCount > 0 ? 'text-warning' : '' }}">
                    {{ number_format($lowStockCount) }}
                </span>
                <span class="stat-tile-note">
                    {{ request()->boolean('low_stock') ? __('showing only these') : __('tap to show only these') }}
                </span>
            </a>
        </div>
        <div class="col-12 col-lg-6">
            {{-- Not quantity times the suggested price: the money that actually
                 left the till for the units still on the shelf. --}}
            <div class="stat-tile">
                <span class="stat-tile-label">{{ __('Stock value') }}</span>
                <span class="stat-tile-value">{{ money($stockValue) }}</span>
                <span class="stat-tile-note">{{ __('what the unsold batches cost') }}</span>
            </div>
        </div>
    </div>

    {{-- Section 9b: filters row, results table, pagination. --}}
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label small">{{ __('Search') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm"
                       placeholder="{{ __('Name, SKU or barcode') }}">
            </div>

            {{-- Section 9: filter by one or SEVERAL categories at once. --}}
            <div class="col-md-4">
                <label for="categories" class="form-label small">{{ __('Categories') }}</label>
                <select id="categories" name="categories[]" multiple size="1" class="form-select form-select-sm">
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}"
                                @selected(in_array($category->id, (array) request('categories', [])))>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2">
                <label for="status" class="form-label small">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="active" @selected(request('status') === 'active')>{{ __('Active') }}</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>{{ __('Inactive') }}</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1">{{ __('Filter') }}</button>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>

            <div class="col-12">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="low_stock" name="low_stock" value="1"
                           @checked(request()->boolean('low_stock'))>
                    <label class="form-check-label small" for="low_stock">{{ __('Low stock only') }}</label>
                </div>
            </div>
        </div>
    </form>

    @if($products->isEmpty())
        <div class="card">
            <x-empty-state icon="box-seam"
                           :message="__('No products yet. Add your first product.')"
                           :action="Gate::allows('products.create') ? route('products.create') : null"
                           :action-label="__('New product')" />
        </div>
    @else
        <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th style="width: 2rem">
                                <input type="checkbox" class="form-check-input" id="bulk-select-all"
                                       aria-label="{{ __('Select all') }}">
                            </th>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th class="money">{{ __('In stock') }}</th>
                            <th class="money">{{ __('Purchase price') }}</th>
                            <th class="money">{{ __('Sale price') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $product)
                            <tr class="{{ $product->is_active ? '' : 'opacity-50' }}">
                                <td>
                                    <input type="checkbox" class="form-check-input"
                                           data-bulk-id="{{ $product->id }}"
                                           aria-label="{{ $product->name }}">
                                </td>
                                <td>
                                    <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-medium">
                                        {{ $product->name }}
                                    </a>
                                    <div class="small text-secondary" dir="ltr">
                                        {{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif
                                    </div>
                                </td>
                                <td>{{ $product->category->name }}</td>
                                <td class="money">
                                    <span class="{{ $product->isLowStock() ? 'text-warning fw-semibold' : '' }}">
                                        {{ number_format($product->quantity) }}
                                    </span>
                                    <span class="text-secondary small">{{ $product->unit }}</span>
                                </td>
                                <td class="money text-secondary">{{ money($product->purchase_price, false) }}</td>
                                <td class="money">{{ money($product->sale_price, false) }}</td>
                                <td class="text-end">
                                    <x-row-actions
                                        :view="route('products.show', $product)"
                                        :edit="Gate::allows('products.edit') ? route('products.edit', $product) : null"
                                        :delete="Gate::allows('products.delete') ? route('products.destroy', $product) : null"
                                        :delete-label="__('Delete :name? Products with stock history are deactivated instead.', ['name' => $product->name])" />
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        {{-- Section 8b: moving, exporting and deleting the ticked rows. The bar
             carries the ids into its own forms, so nothing here wraps the table
             — a form around it would swallow each row's delete form. --}}
        <x-bulk-actions
            :action="Gate::allows('products.delete') ? route('products.bulk-destroy') : null"
            :export="route('products.bulk-export')"
            :move="Gate::allows('products.edit') ? route('categories.bulk-assign') : null"
            :categories="$categories"
            :confirm="__('Delete the selected products?')" />

        <div class="mt-3">{{ $products->links() }}</div>
    @endif
@endsection
