<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductCodeService;
use App\Services\StockAdjustmentService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Adding or editing a product.
 *
 * The same rules the form request enforced, checked as the shopkeeper types
 * rather than after they press Save: a SKU already in use is said so while the
 * cursor is still in the box, and a barcode with a bad check digit is caught
 * before it reaches a till that will not scan it.
 *
 * What is deliberately not live is the saving. One button, one transaction, the
 * same services as before — the FIFO engine is not something to sprinkle
 * through a component's lifecycle.
 */
class Form extends Component
{
    public ?Product $product = null;

    public string $name = '';

    public string $sku = '';

    public string $barcode = '';

    public ?int $category_id = null;

    public string $unit = 'pcs';

    public int $purchase_price = 0;

    public int $sale_price = 0;

    public ?int $reorder_level = null;

    public bool $is_active = true;

    /** Section 5: a product already in the shop needs a first FIFO layer. */
    public ?int $opening_quantity = null;

    public ?int $opening_unit_cost = null;

    public function mount(?Product $product = null): void
    {
        if (! $product?->exists) {
            $this->category_id = Category::orderBy('name')->value('id');

            return;
        }

        $this->product = $product;
        $this->name = $product->name;
        $this->sku = $product->sku;
        $this->barcode = (string) $product->barcode;
        $this->category_id = $product->category_id;
        $this->unit = $product->unit;
        $this->purchase_price = (int) $product->purchase_price;
        $this->sale_price = (int) $product->sale_price;
        $this->reorder_level = $product->reorder_level;
        $this->is_active = (bool) $product->is_active;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Section 4: typed manufacturer codes and generated SS-numbers share
            // this column. Soft-deleted rows keep theirs, so the check ignores
            // the scope rather than handing out a code that is already taken.
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($this->product)->withoutTrashed(),
            ],
            'barcode' => [
                'nullable', 'string', 'max:32',
                Rule::unique('products', 'barcode')->ignore($this->product)->withoutTrashed(),

                // A typed barcode that fails its check digit will not scan at
                // the counter, so it is refused at entry rather than discovered
                // at the till. Live now, rather than after Save.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (preg_match('/^\d{13}$/', (string) $value)
                        && ! app(ProductCodeService::class)->isValidEan13((string) $value)) {
                        $fail(__('That barcode has an invalid EAN-13 check digit.'));
                    }
                },
            ],

            'category_id' => ['required', 'exists:categories,id'],
            'unit' => ['required', 'string', 'max:32'],

            // Section 2: IQD is whole numbers. No decimals anywhere.
            'purchase_price' => ['required', 'integer', 'min:0'],
            'sale_price' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],

            'opening_quantity' => ['nullable', 'integer', 'min:0'],
            'opening_unit_cost' => ['nullable', 'integer', 'min:0', 'required_with:opening_quantity'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'opening_unit_cost.required_with' => __('Opening stock needs a unit cost — FIFO needs a cost for every unit.'),
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'sku' => __('SKU'),
            'barcode' => __('Barcode'),
            'category_id' => __('Category'),
            'purchase_price' => __('Purchase price'),
            'sale_price' => __('Sale price'),
            'reorder_level' => __('Reorder level'),
            'opening_quantity' => __('Quantity'),
            'opening_unit_cost' => __('Cost each'),
        ];
    }

    public function updated(string $field): void
    {
        $this->validateOnly($field);
    }

    /** What the shop would make on each one at these prices. */
    #[Computed]
    public function margin(): ?int
    {
        return $this->sale_price > 0 ? $this->sale_price - $this->purchase_price : null;
    }

    public function save()
    {
        $this->authorize($this->product ? 'products.edit' : 'products.create');

        $data = $this->validate();

        $product = DB::transaction(function () use ($data) {
            // Section 4: codes are generated inside the transaction so the
            // counter and the product row commit together.
            $codes = app(ProductCodeService::class)->resolve([
                'sku' => $this->sku, 'barcode' => $this->barcode,
            ]);

            $attributes = [
                ...collect($data)->except(['sku', 'barcode', 'opening_quantity', 'opening_unit_cost'])->all(),
                'sku' => $codes['sku'],
                'barcode' => $codes['barcode'],
                'is_active' => $this->is_active,
            ];

            if ($this->product) {
                $this->product->update($attributes);

                return $this->product;
            }

            $product = Product::create([...$attributes, 'quantity' => 0]);

            // Section 4 allows only `purchase` or `adjustment` as a batch
            // source, so opening stock is an incoming adjustment.
            if ((int) $this->opening_quantity > 0) {
                app(StockAdjustmentService::class)->recordOpeningStock(
                    product: $product,
                    quantity: (int) $this->opening_quantity,
                    unitCost: (int) $this->opening_unit_cost,
                    user: auth()->user(),
                );
            }

            return $product;
        });

        session()->flash('success', __('Product saved'));

        return $this->redirectRoute('products.show', $product);
    }

    public function render(): View
    {
        return view('livewire.products.form', [
            'categories' => Category::orderBy('name')->get(),
        ])
            ->extends('layouts.app')
            ->section('content');
    }
}
