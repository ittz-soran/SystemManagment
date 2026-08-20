@php $isNew = ! $product->exists; @endphp

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">{{ __('Details') }}</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('Name') }}</label>
                    <input id="name" name="name" value="{{ old('name', $product->name) }}"
                           class="form-control @error('name') is-invalid @enderror" required autofocus>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="sku" class="form-label">{{ __('SKU') }}</label>
                        <input id="sku" name="sku" value="{{ old('sku', $product->sku) }}" dir="ltr"
                               class="form-control @error('sku') is-invalid @enderror"
                               placeholder="{{ __('Leave blank to generate :prefix…', ['prefix' => setting('sku_prefix', 'SS')]) }}">
                        {{-- Section 4: type the manufacturer code, or leave blank
                             and the system generates SS + the next number. --}}
                        <div class="form-text">{{ __('Type the manufacturer code, or leave blank to generate one.') }}</div>
                        @error('sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="barcode" class="form-label">{{ __('Barcode') }}</label>
                        <input id="barcode" name="barcode" value="{{ old('barcode', $product->barcode) }}" dir="ltr"
                               class="form-control @error('barcode') is-invalid @enderror"
                               placeholder="{{ __('Scan, type, or leave blank') }}">
                        <div class="form-text">{{ __('Left blank, an EAN-13 is generated so the product scans at the till.') }}</div>
                        @error('barcode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="category_id" class="form-label">{{ __('Category') }}</label>
                        <select id="category_id" name="category_id"
                                class="form-select @error('category_id') is-invalid @enderror" required>
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}"
                                        @selected(old('category_id', $product->category_id) == $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="unit" class="form-label">{{ __('Unit') }}</label>
                        <input id="unit" name="unit" value="{{ old('unit', $product->unit ?? 'pcs') }}"
                               class="form-control @error('unit') is-invalid @enderror" required>
                        @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">{{ __('Prices') }}</div>
            <div class="card-body">
                {{-- Section 4: these are only default suggestions for the cart. --}}
                <p class="small text-secondary">
                    {{ __('Suggestions for the cart only. The real cost of each unit comes from its purchase batch.') }}
                </p>

                <div class="mb-3">
                    <label for="purchase_price" class="form-label">{{ __('Purchase price') }}</label>
                    <div class="input-group">
                        <input id="purchase_price" type="number" step="1" min="0" name="purchase_price"
                               value="{{ old('purchase_price', $product->purchase_price ?? 0) }}" dir="ltr"
                               class="form-control text-end @error('purchase_price') is-invalid @enderror" required>
                        <span class="input-group-text">{{ __('IQD') }}</span>
                        @error('purchase_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="sale_price" class="form-label">{{ __('Sale price') }}</label>
                    <div class="input-group">
                        <input id="sale_price" type="number" step="1" min="0" name="sale_price"
                               value="{{ old('sale_price', $product->sale_price ?? 0) }}" dir="ltr"
                               class="form-control text-end @error('sale_price') is-invalid @enderror" required>
                        <span class="input-group-text">{{ __('IQD') }}</span>
                        @error('sale_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="reorder_level" class="form-label">{{ __('Reorder level') }}</label>
                    <input id="reorder_level" type="number" step="1" min="0" name="reorder_level"
                           value="{{ old('reorder_level', $product->reorder_level) }}" dir="ltr"
                           class="form-control text-end @error('reorder_level') is-invalid @enderror"
                           placeholder="{{ setting('low_stock_threshold', 0) }}">
                    <div class="form-text">
                        {{ __('Blank uses the shop default of :count.', ['count' => setting('low_stock_threshold', 0)]) }}
                    </div>
                    @error('reorder_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $product->is_active ?? true))>
                    <label class="form-check-label" for="is_active">{{ __('Active') }}</label>
                </div>
            </div>
        </div>

        @if($isNew)
            {{-- Section 5: products already in the shop need a starting batch —
                 quantity plus its cost — or FIFO has no first layer. --}}
            <div class="card">
                <div class="card-header">{{ __('Opening stock') }}</div>
                <div class="card-body">
                    <p class="small text-secondary">
                        {{ __('If you already hold this product, enter how many and what each one cost you. That becomes its first FIFO layer.') }}
                    </p>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="opening_quantity" class="form-label">{{ __('Quantity') }}</label>
                            <input id="opening_quantity" type="number" step="1" min="0" name="opening_quantity"
                                   value="{{ old('opening_quantity') }}" dir="ltr"
                                   class="form-control text-end @error('opening_quantity') is-invalid @enderror">
                            @error('opening_quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="opening_unit_cost" class="form-label">{{ __('Cost each') }}</label>
                            <input id="opening_unit_cost" type="number" step="1" min="0" name="opening_unit_cost"
                                   value="{{ old('opening_unit_cost') }}" dir="ltr"
                                   class="form-control text-end @error('opening_unit_cost') is-invalid @enderror">
                            @error('opening_unit_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Saving…') }}">
        {{ __('Save product') }}
    </button>
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
</div>
