<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Buying one second-hand thing from whoever walked in with it.
 *
 * The whole feature is this one method, and what it does not do is the point:
 * it invents no costing, writes no batch and touches no ledger of its own. It
 * creates the item and hands it to PurchaseService, which does what it does for
 * every other purchase — opens the batch, posts what is owed, records what was
 * paid. Rule 1 is untouched.
 *
 * That works because of how the item is shaped. A second-hand Xbox is not "one
 * of the Xboxes"; it is a particular machine with a particular history, bought
 * for a particular price. Two of them are not interchangeable and averaging or
 * queueing them would give a made-up cost to a real sale. So each gets its own
 * row holding one unit — which makes FIFO exactly right rather than merely
 * tolerable, because with a single batch there is nothing to choose between and
 * the cost recorded against the sale is the money that actually left the till.
 *
 * The seller is recorded as a supplier, marked as walked in. Not because they
 * are a supplier — they sold the shop one console and will likely never return
 * — but because "what does the shop still owe this person" is a question the
 * ledger already answers, and answering it twice, in two places, is how the two
 * come to disagree.
 */
class SecondHandService
{
    public function __construct(
        private PurchaseService $purchases,
        private ProductCodeService $codes,
    ) {}

    /** The category a second-hand item falls into when none is chosen. */
    public const DEFAULT_CATEGORY = 'Second-hand';

    /**
     * @param  array{
     *     name: string, cost: int, sale_price: int,
     *     seller_name: string, seller_phone?: string|null,
     *     condition_note?: string|null, category_id?: int|null,
     *     unit?: string|null, amount_paid?: int|null, payment_method?: string|null,
     * }  $input
     * @return array{product: Product, purchase: Purchase, seller: Supplier}
     */
    public function buy(array $input, User $user, ?Carbon $boughtAt = null): array
    {
        $boughtAt ??= now();

        if (($input['cost'] ?? 0) < 0) {
            throw new RuntimeException(__('The price paid cannot be negative.'));
        }

        return DB::transaction(function () use ($input, $user, $boughtAt) {
            $seller = $this->seller($input['seller_name'], $input['seller_phone'] ?? null);

            $codes = $this->codes->resolve([]);

            $product = Product::create([
                'name' => trim($input['name']),
                'kind' => Product::KIND_USED,
                'sku' => $codes['sku'],
                'barcode' => $codes['barcode'],
                'category_id' => $input['category_id'] ?? $this->defaultCategory()->id,
                'unit' => $input['unit'] ?? 'pcs',
                'condition_note' => $input['condition_note'] ?? null,
                'acquired_from_id' => $seller->id,
                'purchase_price' => (int) $input['cost'],
                'sale_price' => (int) $input['sale_price'],
                // Section 4: a cache of the batches, which the purchase is about
                // to write. Never set by hand.
                'quantity' => 0,
                // One of a kind: there is no level at which to reorder it.
                'reorder_level' => 0,
                'is_active' => true,
            ]);

            $purchase = $this->purchases->create(
                supplier: $seller,
                lines: [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => (int) $input['cost'],
                ]],
                user: $user,
                purchaseDate: $boughtAt,
                amountPaid: (int) ($input['amount_paid'] ?? 0),
                paymentMethod: $input['payment_method'] ?? 'cash',
            );

            return [
                'product' => $product->refresh(),
                'purchase' => $purchase,
                'seller' => $seller->refresh(),
            ];
        });
    }

    /**
     * The same person, recognised.
     *
     * Matched on phone, because that is what someone gives twice the same way —
     * a name is spelled three ways by three different hands. With no phone
     * there is nothing to match on, so a new record is made rather than
     * guessing that two people with one common name are one person.
     */
    public function seller(string $name, ?string $phone): Supplier
    {
        $name = trim($name);
        $phone = filled($phone) ? trim($phone) : null;

        if ($name === '') {
            throw new RuntimeException(__('The seller needs a name.'));
        }

        $existing = $phone === null
            ? null
            : Supplier::walkIns()->where('phone', $phone)->first();

        if ($existing) {
            return $existing;
        }

        return Supplier::create([
            'name' => $name,
            'phone' => $phone,
            'is_walk_in' => true,
            'is_active' => true,
        ]);
    }

    private function defaultCategory(): Category
    {
        // Not translated: this is a row's name, not a label on a screen. Read
        // through __() it would find nothing in Kurdish and make a second
        // category, and the shop would have two of everything. It is seeded in
        // English and the shopkeeper can rename it to whatever they like.
        return Category::firstOrCreate(['name' => self::DEFAULT_CATEGORY]);
    }
}
