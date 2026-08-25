@section('title', $product ? __('Edit product') : __('New product'))

@if($product)
    @section('subheading', $product->name)

    @section('back')
        <x-back-link :to="route('products.show', $product)" :label="$product->name"
                     permission="products.view" />
    @endsection
@else
    @section('back')
        <x-back-link :to="route('products.index')" :label="__('Products')"
                     remember="products" permission="products.view" />
    @endsection
@endif

<form wire:submit="save" class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">{{ __('Details') }}</div>
            <div class="card-body">
                <div class="field">
                    <label for="name" class="form-label">{{ __('Name') }}</label>
                    <input id="name" wire:model.live.blur="name" autofocus
                           class="form-control form-control-lg @error('name') is-invalid @enderror"
                           placeholder="{{ __('USB Cable 2m') }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6 field">
                        <label for="sku" class="form-label">{{ __('SKU') }}</label>
                        <input id="sku" wire:model.live.blur="sku" dir="ltr"
                               class="form-control @error('sku') is-invalid @enderror"
                               placeholder="{{ __('Leave blank to generate :prefix…', ['prefix' => setting('sku_prefix', 'SS')]) }}">
                        <div class="form-text">{{ __('Type the manufacturer code, or leave blank to generate one.') }}</div>
                        @error('sku')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6 field">
                        <label for="barcode" class="form-label">{{ __('Barcode') }}</label>
                        <input id="barcode" wire:model.live.blur="barcode" dir="ltr"
                               class="form-control @error('barcode') is-invalid @enderror"
                               placeholder="{{ __('Scan, type, or leave blank') }}">
                        <div class="form-text">{{ __('Left blank, an EAN-13 is generated so the product scans at the till.') }}</div>
                        {{-- Caught here rather than at the counter, where the
                             scanner simply refuses and nobody knows why. --}}
                        @error('barcode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6 field">
                        <label for="category_id" class="form-label">{{ __('Category') }}</label>
                        <select id="category_id" wire:model.live.blur="category_id"
                                class="form-select @error('category_id') is-invalid @enderror">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6 field">
                        <label for="unit" class="form-label">{{ __('Unit') }}</label>
                        <input id="unit" wire:model.live.blur="unit" list="units"
                               class="form-control @error('unit') is-invalid @enderror">
                        <datalist id="units">
                            @foreach(['pcs', 'box', 'm', 'kg', 'set', 'pair'] as $unit)
                                <option value="{{ $unit }}"></option>
                            @endforeach
                        </datalist>
                        @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <hr class="my-4">

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" wire:model.live="is_active">
                    <label class="form-check-label" for="is_active">
                        {{ __('Active') }}
                        <span class="d-block small text-secondary">
                            {{ $is_active
                                ? __('Offered in the sale and purchase carts.')
                                : __('Kept out of the carts, and left on every document it is already on.') }}
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">{{ __('Prices') }}</div>
            <div class="card-body">
                <p class="small text-secondary">
                    {{ __('Suggestions for the cart only. The real cost of each unit comes from its purchase batch.') }}
                </p>

                <div class="field">
                    <label for="purchase_price" class="form-label">{{ __('Purchase price') }}</label>
                    <div class="input-group">
                        <input id="purchase_price" type="number" step="1" min="0" dir="ltr" data-numpad
                               wire:model.live.debounce.400ms="purchase_price"
                               class="form-control text-end @error('purchase_price') is-invalid @enderror">
                        <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                    </div>
                    @error('purchase_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="sale_price" class="form-label">{{ __('Sale price') }}</label>
                    <div class="input-group">
                        <input id="sale_price" type="number" step="1" min="0" dir="ltr" data-numpad
                               wire:model.live.debounce.400ms="sale_price"
                               class="form-control text-end @error('sale_price') is-invalid @enderror">
                        <span class="input-group-text">{{ setting('currency', 'IQD') }}</span>
                    </div>
                    @error('sale_price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- The number the two prices are really for, worked out as they
                     are typed rather than in the shopkeeper's head. --}}
                @if($this->margin !== null)
                    <div class="margin-readout {{ $this->margin < 0 ? 'is-negative' : '' }}">
                        <span>{{ $this->margin < 0 ? __('Loss on each') : __('Profit on each') }}</span>
                        <strong class="money">{{ money(abs($this->margin), false) }}</strong>
                        @if($sale_price > 0)
                            <span class="margin-readout-pct">
                                {{ (int) round($this->margin / $sale_price * 100) }}%
                            </span>
                        @endif
                    </div>
                @endif

                <div class="field mt-3">
                    <label for="reorder_level" class="form-label">{{ __('Reorder level') }}</label>
                    <input id="reorder_level" type="number" step="1" min="0" dir="ltr"
                           wire:model.live.blur="reorder_level"
                           class="form-control text-end @error('reorder_level') is-invalid @enderror"
                           placeholder="{{ setting('low_stock_threshold', 0) }}">
                    <div class="form-text">
                        {{ __('Blank uses the shop default of :count.', ['count' => setting('low_stock_threshold', 0)]) }}
                    </div>
                    @error('reorder_level')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        @unless($product)
            {{-- Section 5: a product already in the shop needs a starting batch —
                 quantity AND its cost — or FIFO has no first layer. --}}
            <div class="card mb-3">
                <div class="card-header">{{ __('Opening stock') }}</div>
                <div class="card-body">
                    <p class="small text-secondary">
                        {{ __('If you already hold this product, enter how many and what each one cost you. That becomes its first FIFO layer.') }}
                    </p>

                    <div class="row g-3">
                        <div class="col-6 field">
                            <label for="opening_quantity" class="form-label">{{ __('Quantity') }}</label>
                            <input id="opening_quantity" type="number" step="1" min="0" dir="ltr" data-numpad
                                   wire:model.live.debounce.400ms="opening_quantity"
                                   class="form-control text-end @error('opening_quantity') is-invalid @enderror">
                            @error('opening_quantity')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 field">
                            <label for="opening_unit_cost" class="form-label">{{ __('Cost each') }}</label>
                            <input id="opening_unit_cost" type="number" step="1" min="0" dir="ltr" data-numpad
                                   wire:model.live.debounce.400ms="opening_unit_cost"
                                   class="form-control text-end @error('opening_unit_cost') is-invalid @enderror">
                            @error('opening_unit_cost')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    @if((int) $opening_quantity > 0 && (int) $opening_unit_cost > 0)
                        <div class="form-hint mt-3">
                            <i class="bi bi-layers"></i>
                            {{ __('Opens one batch of :count at :cost each — :total in stock value.', [
                                'count' => number_format((int) $opening_quantity),
                                'cost' => money((int) $opening_unit_cost, false),
                                'total' => money((int) $opening_quantity * (int) $opening_unit_cost, false),
                            ]) }}
                        </div>
                    @endif
                </div>
            </div>
        @endunless

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">
                    <i class="bi bi-check-lg me-1"></i>{{ __('Save') }}
                </span>
                <span wire:loading wire:target="save">
                    <span class="spinner-border spinner-border-sm me-2"></span>{{ __('Saving…') }}
                </span>
            </button>

            <a href="{{ $product ? route('products.show', $product) : route('products.index') }}"
               class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
        </div>
    </div>
</form>
