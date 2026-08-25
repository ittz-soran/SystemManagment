<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Services\ProductCodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission($this->product ? 'products.edit' : 'products.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],

            // Section 4: both typed manufacturer codes and auto-generated `SS65`
            // values live in this column, unique across all products. Soft-deleted
            // rows still hold theirs, so the uniqueness check ignores the scope.
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($product)->withoutTrashed(),
            ],
            'barcode' => [
                'nullable', 'string', 'max:32',
                Rule::unique('products', 'barcode')->ignore($product)->withoutTrashed(),
            ],

            'category_id' => ['required', 'exists:categories,id'],
            'unit' => ['required', 'string', 'max:32'],

            // Section 2: IQD is whole numbers. No decimals anywhere.
            'purchase_price' => ['required', 'integer', 'min:0'],
            'sale_price' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],

            // Section 5: opening stock. A product already in the shop needs a
            // starting batch — quantity AND its cost — or FIFO has no first layer.
            'opening_quantity' => ['nullable', 'integer', 'min:0'],
            'opening_unit_cost' => ['nullable', 'integer', 'min:0', 'required_with:opening_quantity'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $barcode = $this->input('barcode');

            // A typed barcode that fails its check digit will not scan at the
            // till, so catch it at entry rather than at the counter.
            if (filled($barcode) && preg_match('/^\d{13}$/', $barcode)
                && ! app(ProductCodeService::class)->isValidEan13($barcode)) {
                $validator->errors()->add('barcode', __('That barcode has an invalid EAN-13 check digit.'));
            }

            if ((int) $this->input('opening_quantity') > 0 && $this->route('product')) {
                $validator->errors()->add(
                    'opening_quantity',
                    __('Opening stock is only set when the product is created. Use a stock adjustment instead.')
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'opening_unit_cost.required_with' => __('Opening stock needs a unit cost — FIFO needs a cost for every unit.'),
        ];
    }
}
