<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Units;
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
     * The most bought at once — Soran, 2026-10-20. A slip that types 200 would
     * make two hundred products with two hundred barcodes in one press.
     */
    public const MOST_AT_ONCE = 20;

    /**
     * @param  array{
     *     name: string, cost: int, sale_price: int,
     *     seller_name: string, seller_phone?: string|null, seller_id?: int|null,
     *     condition_note?: string|null, category_id?: int|null,
     *     unit?: string|null, amount_paid?: int|null, payment_method?: string|null,
     *     quantity?: int|null,
     * }  $input
     * @return array{product: Product, products: list<Product>, purchase: Purchase, seller: Supplier}
     */
    public function buy(array $input, User $user, ?Carbon $boughtAt = null): array
    {
        $boughtAt ??= now();

        if (($input['cost'] ?? 0) < 0) {
            throw new RuntimeException(__('The price paid cannot be negative.'));
        }

        /*
         * ⚠️ Several of the same — Soran, 2026-10-20: *"today i bought 2
         * laptop dell both same"*. Each is still its own item holding one unit,
         * for the reason at the top of this class; they share one purchase,
         * because it was one deal with one person. PROJECT_DOC Section 9.
         */
        $howMany = (int) ($input['quantity'] ?? 1);

        if ($howMany < 1 || $howMany > self::MOST_AT_ONCE) {
            throw new RuntimeException(__('Buy between 1 and :most at a time.', ['most' => self::MOST_AT_ONCE]));
        }

        return DB::transaction(function () use ($input, $user, $boughtAt, $howMany) {
            $seller = $this->seller(
                $input['seller_name'],
                $input['seller_phone'] ?? null,
                $input['seller_id'] ?? null,
            );

            $products = [];

            for ($i = 0; $i < $howMany; $i++) {
                $products[] = $this->item($input, $seller);
            }

            $purchase = $this->purchases->create(
                supplier: $seller,
                lines: array_map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => (int) $input['cost'],
                ], $products),
                user: $user,
                purchaseDate: $boughtAt,
                amountPaid: (int) ($input['amount_paid'] ?? 0),
                paymentMethod: $input['payment_method'] ?? 'cash',
            );

            $products = array_map(fn (Product $product) => $product->refresh(), $products);

            return [
                'product' => $products[0],
                'products' => $products,
                'purchase' => $purchase,
                'seller' => $seller->refresh(),
            ];
        });
    }

    /**
     * One second-hand item: its own row, its own codes, nothing in stock until
     * the purchase opens its batch.
     *
     * @param  array<string, mixed>  $input
     */
    private function item(array $input, Supplier $seller): Product
    {
        $codes = $this->codes->resolve([]);

        return Product::create([
            'name' => trim($input['name']),
            'kind' => Product::KIND_USED,
            'sku' => $codes['sku'],
            'barcode' => $codes['barcode'],
            'category_id' => $input['category_id'] ?? $this->defaultCategory()->id,
            'unit' => $input['unit'] ?? Units::default(),
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
    }

    /**
     * The person the shop bought it from — the one that was chosen, or a new one.
     *
     * This used to match on phone by itself, and that was wrong twice over. It
     * quietly attached a buy to somebody the shopkeeper had not named, and
     * because the matched record keeps its own name, the second person's name
     * simply vanished — every later buy came out under the first person's name
     * with no way to say otherwise.
     *
     * So nothing is matched behind anyone's back. The form searches the people
     * already bought from, by name and by number; picking one sends its id here
     * and that is who it is. Typing instead makes a new person. The shopkeeper
     * decides, every time.
     */
    public function seller(string $name, ?string $phone, ?int $sellerId = null): Supplier
    {
        $name = trim($name);
        $phone = filled($phone) ? trim($phone) : null;

        if ($name === '') {
            throw new RuntimeException(__('The seller needs a name.'));
        }

        if ($sellerId !== null) {
            $chosen = Supplier::walkIns()->find($sellerId);

            if ($chosen) {
                // A number added on a later visit is worth keeping; a name is
                // not overwritten, since this is the person who was chosen.
                if ($phone !== null && blank($chosen->phone)) {
                    $chosen->update(['phone' => $phone]);
                }

                return $chosen;
            }
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
