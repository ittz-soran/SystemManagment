<?php

namespace App\Http\Controllers;

use App\Models\HeldCart;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Putting a cart down, and picking it up again.
 *
 * Nothing here writes to the shop's books. A held cart takes no document
 * number, opens no batch, moves no stock and posts nothing to the ledger — all
 * of which happens when the real document is saved, and only then. A cart that
 * reserved its stock would hide those units from the till at the other end of
 * the counter, and FIFO would be picking off a shelf that disagrees with the
 * room.
 */
class HeldCartController extends Controller
{
    /** Put the cart down. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:sale,purchase'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            // Section 6b: a purchase line may have been typed in dollars at a
            // rate. Dropped here, the line would come back as if it had been
            // typed in dinars, and the money would silently change.
            'lines.*.entered_currency' => ['nullable', 'in:IQD,USD'],
            'lines.*.entered_amount' => ['nullable', 'integer', 'min:0'],
            // Whoever had been chosen, if anybody had. The whole point of this
            // feature is the cart where nobody has been.
            'party_id' => ['nullable', 'integer'],
        ]);

        Gate::authorize($data['type'] === HeldCart::TYPE_SALE ? 'sales.create' : 'purchases.create');

        $cart = HeldCart::create([
            'type' => $data['type'],
            'user_id' => $request->user()->id,
            'note' => $data['note'] ?? null,
            'payload' => [
                'lines' => $data['lines'],
                'party_id' => $data['party_id'] ?? null,
            ],
        ]);

        return response()->json([
            'id' => $cart->id,
            'message' => __('Cart held. It is waiting on this screen.'),
        ]);
    }

    /** Throw it away. */
    public function destroy(Request $request, HeldCart $heldCart): RedirectResponse
    {
        Gate::authorize($heldCart->type === HeldCart::TYPE_SALE ? 'sales.create' : 'purchases.create');

        $heldCart->delete();

        return back()->with('success', __('Held cart discarded'));
    }

    /**
     * The lines of a held cart, rebuilt against the shelf as it is now.
     *
     * Only the product, the quantity and the price were kept. Everything else
     * the cart shows — what is in stock, what the next unit costs, whether the
     * price is under it — is worked out again here, because all of that moves
     * while a cart sits and a cart picked up tomorrow must show tomorrow's
     * shelf rather than a photograph of yesterday's.
     *
     * A product deleted since the cart was held simply drops out. Better a
     * short cart than a line pointing at nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rebuild(HeldCart $cart, callable $nextBatchCost): array
    {
        return self::linesFor($cart->lines(), $nextBatchCost);
    }

    /**
     * The same shape, from raw lines rather than from a held cart.
     *
     * **Split out 2026-09-12, for the submit that comes back.** A sale refused
     * for a reason the screen could have caught — the Cash Customer not paid in
     * full — threw the whole basket away and left an empty cart. The lines were
     * never lost: `store()` sends them back with `withInput()`. Nothing read
     * them, so the page rebuilt itself empty and the shopkeeper re-scanned
     * everything to fix one field. At a till with a queue that is the difference
     * between a correction and a disaster.
     *
     * Tolerant of half-typed lines on purpose. These may have come from a
     * request that failed validation, so a missing quantity is exactly the
     * case to expect — a line with no usable product is dropped, and anything
     * else missing comes back as zero for the shopkeeper to correct. Refusing
     * to render would be losing the basket all over again, for the same reason.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function linesFor(array $lines, callable $nextBatchCost): array
    {
        $products = Product::whereIn('id', collect($lines)->pluck('product_id')->filter())
            ->get()
            ->keyBy('id');

        return collect($lines)
            ->map(function (array $line) use ($products, $nextBatchCost) {
                $product = $products[$line['product_id'] ?? null] ?? null;

                if (! $product) {
                    return null;
                }

                $quantity = (int) ($line['quantity'] ?? 0);
                $price = (int) ($line['unit_price'] ?? 0);

                $cost = $nextBatchCost($product);

                // Section 6b: kept as it was typed. A line entered in dollars
                // comes back in dollars, at the amount that was typed, or the
                // shopkeeper would find the price had changed under them.
                $currency = $line['entered_currency'] ?? 'IQD';

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $quantity,
                    'price' => $price,
                    'currency' => $currency,
                    'enteredAmount' => $currency === 'USD'
                        ? round(((int) ($line['entered_amount'] ?? 0)) / 100, 2)
                        : 0,
                    'stock' => (int) $product->quantity,
                    'cost' => $cost,
                    'belowCost' => $cost !== null && $price < $cost,
                    'kind' => $product->kind,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
