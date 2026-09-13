<?php

namespace App\Http\Requests;

use App\Models\Currency;
use App\Models\Product;
use App\Rules\Amount;
use App\Services\ProductCodeService;
use App\Support\MoneyInput;
use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission($this->product ? 'products.edit' : 'products.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Section 4: both typed manufacturer codes and auto-generated `SS65`
            // values live in this column, unique across all products.
            //
            // Uniqueness is checked below rather than with Rule::unique, so the
            // message can say which product is holding the code — and, when
            // that product has been deleted, that it can be had back. "The sku
            // has already been taken" sends a shopkeeper hunting through a list
            // for something that may not be on it.
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:32'],

            'category_id' => ['required', 'exists:categories,id'],
            'unit' => ['required', 'string', 'max:32'],

            /*
             * Section 2: what is STORED is whole base-currency units, always.
             * Section 2b: what is TYPED may be another currency, so the check
             * is `Amount` rather than `integer` — under no lens the two are the
             * same rule, under one `12.50` is a price and `integer` refuses it.
             *
             * Not asked of somebody whose cost is masked — the form shows them
             * the mask rather than a field, so there is nothing for them to
             * post, and prepareForValidation puts back whatever is already
             * stored.
             */
            'purchase_price' => [$this->user()->seesRealCost() ? 'required' : 'nullable', new Amount($this->lens(), min: 0)],
            'sale_price' => ['required', new Amount($this->lens(), min: 0)],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],

            // Section 5: opening stock. A product already in the shop needs a
            // starting batch — quantity AND its cost — or FIFO has no first layer.
            'opening_quantity' => ['nullable', 'integer', 'min:0'],
            'opening_unit_cost' => ['nullable', new Amount($this->lens(), min: 0), 'required_with:opening_quantity'],
        ];
    }

    /** Section 2b — the currency this person is typing in, base if none. */
    public function lens(): ?Currency
    {
        return $this->user()->lens();
    }

    /**
     * The three money fields, as the base-currency integers to store.
     *
     * ⚠️ Read through `MoneyInput`, which means the untouched-field rule
     * applies: a price the person did not type into comes back exactly as it
     * was stored. Opening a product in dollars to fix its name must not move
     * its cost by the rounding — see App\Support\MoneyInput.
     *
     * @return array<string, int|null>
     */
    public function prices(?Product $product = null): array
    {
        $lens = $this->lens();

        return [
            'purchase_price' => MoneyInput::fromRequest($this, 'purchase_price', $lens, $product?->purchase_price),
            'sale_price' => MoneyInput::fromRequest($this, 'sale_price', $lens, $product?->sale_price),
        ];
    }

    /** Opening stock's unit cost. Create only, so nothing can be untouched. */
    public function openingUnitCost(): int
    {
        return (int) MoneyInput::fromRequest($this, 'opening_unit_cost', $this->lens());
    }

    /**
     * A cost this reader is not shown is a cost they cannot change.
     *
     * The form gives them the mask instead of a field, so nothing arrives here
     * — and if something did, it would be either the stored figure they should
     * not have seen or a marked-up one that must never be saved as fact. Either
     * way the answer is the same: keep what is already there.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()->seesRealCost()) {
            return;
        }

        /** @var Product|null $product */
        $product = $this->route('product');

        /*
         * ⚠️ The companion field goes in too. `MoneyInput` decides a field was
         * never edited by comparing what was posted against what the box was
         * drawn with; without a match here, a masked reader saving a product
         * under a dollar lens would have the stored dinar cost re-read as
         * dollars and multiplied by the rate.
         */
        $this->merge([
            'purchase_price' => $keep = $product?->purchase_price ?? 0,
            MoneyInput::shownField('purchase_price') => $keep,
            'opening_unit_cost' => null,
            'opening_quantity' => null,
        ]);
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

            // The doc's schema is unique (sku) and unique (barcode) across the
            // whole table, and a soft-deleted row is still in the table. The
            // check used to skip the deleted ones, which meant a code belonging
            // to a deleted product passed validation and then broke on the way
            // into the database — a server error where there should have been a
            // sentence.
            $this->flagCodesAlreadyUsed($validator);

            if ((int) $this->input('opening_quantity') > 0 && $this->route('product')) {
                $validator->errors()->add(
                    'opening_quantity',
                    __('Opening stock is only set when the product is created. Use a stock adjustment instead.')
                );
            }
        });
    }

    /**
     * Say who is holding the SKU or the barcode.
     *
     * Deleted products are searched too, because a soft-deleted row still holds
     * both codes against the unique indexes the doc specifies — and because
     * that case has an answer worth giving: the product holding it can be
     * brought back, which keeps its batches, its movements and every invoice
     * line that names it.
     */
    private function flagCodesAlreadyUsed($validator): void
    {
        /** @var Product|null $editing */
        $editing = $this->route('product');

        foreach (['sku', 'barcode'] as $field) {
            $value = $this->input($field);

            if (blank($value)) {
                continue;
            }

            $holder = Product::withTrashed()
                ->where($field, $value)
                ->when($editing, fn ($q) => $q->whereKeyNot($editing->getKey()))
                ->first();

            if (! $holder) {
                continue;
            }

            $validator->errors()->add($field, match (true) {
                $field === 'sku' && $holder->trashed() => __('That SKU belongs to :name, which was deleted. Bring it back from the deleted list, or use a different SKU.', ['name' => $holder->name]),
                $field === 'sku' => __('That SKU is already :name\'s.', ['name' => $holder->name]),
                $holder->trashed() => __('That barcode belongs to :name, which was deleted. Bring it back from the deleted list, or use a different barcode.', ['name' => $holder->name]),
                default => __('That barcode is already :name\'s.', ['name' => $holder->name]),
            });
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'opening_unit_cost.required_with' => __('Opening stock needs a unit cost — FIFO needs a cost for every unit.'),
        ];
    }
}
