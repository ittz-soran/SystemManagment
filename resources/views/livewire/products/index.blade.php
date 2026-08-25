@section('title', __('Products'))

@section('actions')
    @can('products.create')
        <a href="{{ route('products.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New product') }}
        </a>
    @endcan
@endsection

{{-- Arrived from a category, so there is somewhere to go back to. The script
     turns this into the browser's own Back, which is where the reader came from
     whether that was the category list or anywhere else. --}}
@if($categories !== [])
    @section('back')
        <x-back-link :to="route('categories.index')" :label="__('Categories')"
                     remember="categories" permission="categories.view" />
    @endsection
@endif

<div>
    {{-- Three figures worth knowing before reading the table, and the third is
         the one that needs acting on — so it is a filter, not a decoration. --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <span class="stat-tile-label">{{ __('Products') }}</span>
                <span class="stat-tile-value">{{ number_format($total) }}</span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button"
                    class="stat-tile stat-tile-action w-100 text-start {{ $lowStock ? 'is-on' : '' }}"
                    wire:click="$toggle('lowStock')">
                <span class="stat-tile-label">
                    <i class="bi bi-exclamation-triangle text-warning"></i>
                    {{ __('Low stock') }}
                </span>
                <span class="stat-tile-value {{ $lowStockCount > 0 ? 'text-warning' : '' }}">
                    {{ number_format($lowStockCount) }}
                </span>
                <span class="stat-tile-note">
                    {{ $lowStock ? __('showing only these') : __('tap to show only these') }}
                </span>
            </button>
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

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-lg-4">
                    <label for="search" class="form-label small text-secondary">{{ __('Search') }}</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-body-tertiary border-end-0">
                            <i class="bi bi-search"></i>
                        </span>
                        {{-- Live, but not on every keystroke: 300ms of quiet
                             first, or the shop's till would ask the server nine
                             times for one word. --}}
                        <input id="search" type="search" wire:model.live.debounce.300ms="search"
                               class="form-control border-start-0"
                               placeholder="{{ __('Name, SKU or barcode') }}" autocomplete="off">
                        <span class="input-group-text bg-body-tertiary" wire:loading wire:target="search">
                            <span class="spinner-border spinner-border-sm text-secondary"></span>
                        </span>
                    </div>
                </div>

                <div class="col-lg-4">
                    <label class="form-label small text-secondary">{{ __('Categories') }}</label>
                    <div class="filter-chips">
                        @foreach($allCategories as $category)
                            <label class="filter-chip {{ in_array($category->id, $categories) ? 'is-on' : '' }}">
                                <input type="checkbox" wire:model.live="categories" value="{{ $category->id }}">
                                {{ $category->name }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-2">
                    <label for="status" class="form-label small text-secondary">{{ __('Status') }}</label>
                    <select id="status" wire:model.live="status" class="form-select form-select-sm">
                        <option value="">{{ __('All') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>

                <div class="col-lg-2">
                    @if($this->filtered)
                        <button type="button" wire:click="clearFilters"
                                class="btn btn-sm btn-outline-secondary w-100">
                            <i class="bi bi-x-lg me-1"></i>{{ __('Clear') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- The bulk bar appears only when something is selected, where the reader
         is already looking, rather than sitting empty at the bottom of the page
         all day. --}}
    @if($selected !== [])
        <div class="bulk-bar mb-3" wire:key="bulk">
            <span class="fw-medium">
                {{ trans_choice('{1}:count selected|[2,*]:count selected', count($selected),
                    ['count' => number_format(count($selected))]) }}
            </span>

            @can('products.edit')
                <div class="dropdown ms-auto">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-tags me-1"></i>{{ __('Move to category') }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @foreach($allCategories as $category)
                            <li>
                                <button class="dropdown-item" type="button"
                                        wire:click="assignCategory({{ $category->id }})">
                                    {{ $category->name }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endcan

            <button class="btn btn-sm btn-outline-light" type="button"
                    wire:click="$set('selected', [])">{{ __('Cancel') }}</button>
        </div>
    @endif

    @if($products->isEmpty())
        <div class="card">
            <x-empty-state icon="box-seam"
                           :message="$this->filtered
                               ? __('No product matches that.')
                               : __('No products yet. Add your first product.')"
                           :action="! $this->filtered && Gate::allows('products.create') ? route('products.create') : null"
                           :action-label="__('New product')" />
        </div>
    @else
        {{-- Dimmed while the server answers, so a slow reply looks like waiting
             rather than like nothing happening. --}}
        <div class="card" wire:loading.class="is-busy" wire:target="search,categories,status,lowStock,sortBy,gotoPage,nextPage,previousPage">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 data-table">
                    <thead>
                    <tr>
                        <th style="width: 2.5rem">
                            <input type="checkbox" class="form-check-input" wire:model.live="selectPage"
                                   aria-label="{{ __('Select all') }}">
                        </th>
                        @foreach([
                            ['name', __('Product'), ''],
                            ['quantity', __('In stock'), 'money'],
                            ['purchase_price', __('Purchase price'), 'money'],
                            ['sale_price', __('Sale price'), 'money'],
                        ] as [$column, $label, $align])
                            <th class="{{ $align }}">
                                <button type="button" class="th-sort" wire:click="sortBy('{{ $column }}')">
                                    {{ $label }}
                                    <i class="bi bi-{{ $sort === $column
                                        ? ($direction === 'asc' ? 'caret-up-fill' : 'caret-down-fill')
                                        : 'arrow-down-up' }} th-sort-icon {{ $sort === $column ? 'is-on' : '' }}"></i>
                                </button>
                            </th>
                            @if($column === 'name')
                                <th>{{ __('Category') }}</th>
                            @endif
                        @endforeach
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($products as $product)
                        <tr wire:key="product-{{ $product->id }}"
                            class="{{ $product->is_active ? '' : 'is-inactive' }} {{ in_array($product->id, $selected) ? 'is-selected' : '' }}">
                            <td>
                                <input type="checkbox" class="form-check-input" wire:model.live="selected"
                                       value="{{ $product->id }}" aria-label="{{ $product->name }}">
                            </td>
                            <td>
                                <a href="{{ route('products.show', $product) }}"
                                   class="fw-medium text-decoration-none">{{ $product->name }}</a>
                                @unless($product->is_active)
                                    <span class="badge text-bg-light">{{ __('Inactive') }}</span>
                                @endunless
                                <div class="small text-secondary" dir="ltr">
                                    {{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif
                                </div>
                            </td>
                            <td>
                                <span class="badge-soft">{{ $product->category->name }}</span>
                            </td>
                            <td class="money">
                                <span class="{{ $product->isLowStock() ? 'text-warning fw-semibold' : 'fw-medium' }}">
                                    {{ number_format($product->quantity) }}
                                </span>
                                <span class="text-secondary small">{{ $product->unit }}</span>
                                @if($product->isLowStock())
                                    <i class="bi bi-exclamation-triangle text-warning small"
                                       title="{{ __('At or below :count', ['count' => $product->effectiveReorderLevel()]) }}"></i>
                                @endif
                            </td>
                            <td class="money text-secondary">{{ money($product->purchase_price, false) }}</td>
                            <td class="money fw-medium">{{ money($product->sale_price, false) }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm row-actions">
                                    <a href="{{ route('products.show', $product) }}"
                                       class="btn btn-outline-secondary" title="{{ __('View') }}">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @can('products.edit')
                                        <a href="{{ route('products.edit', $product) }}"
                                           class="btn btn-outline-secondary" title="{{ __('Edit') }}">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endcan
                                    @can('products.delete')
                                        <button type="button" class="btn btn-outline-danger"
                                                title="{{ __('Delete') }}"
                                                wire:click="delete({{ $product->id }})"
                                                wire:confirm="{{ __('Delete :name? Products with stock history are deactivated instead.', ['name' => $product->name]) }}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $products->links() }}</div>
    @endif
</div>
