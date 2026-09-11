<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The FIFO engine (Section 5) — the core of the system.
 *
 * When stock is sold, the units purchased first are consumed first, at that
 * batch's cost. Every method here must be called inside a transaction, and
 * every read that leads to a write locks its rows first.
 */
class FifoService
{
    /**
     * Create a new stock layer.
     *
     * Section 6: unit_cost is exactly the price typed. Nothing ever changes it —
     * not a whole-invoice discount, not a later exchange-rate move.
     */
    public function createBatch(
        Product $product,
        string $sourceType,
        int $sourceId,
        int $unitCost,
        int $quantity,
        Carbon $receivedAt,
        int $sequence,
        User $user,
        ?int $purchaseItemId = null,
    ): StockBatch {
        $this->assertInTransaction();

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'purchase_item_id' => $purchaseItemId,
            'unit_cost' => $unitCost,
            'quantity_in' => $quantity,
            'quantity_remaining' => $quantity,
            'received_at' => $receivedAt,
            'sequence' => $sequence,
        ]);

        // Section 4: creating the batch is not enough. With purchase rows present,
        // stock_movements alone reconstructs the full history of a product, and
        // SUM(quantity) per product must equal current stock.
        $referenceType = $sourceType === StockBatch::SOURCE_PURCHASE
            ? StockMovement::REF_PURCHASE
            : StockMovement::REF_ADJUSTMENT;

        StockMovement::create([
            'product_id' => $product->id,
            'stock_batch_id' => $batch->id,
            'reference_type' => $referenceType,
            'reference_id' => $sourceId,
            'reference_item_id' => $purchaseItemId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'occurred_at' => $receivedAt,
            'sequence' => $sequence,
            'user_id' => $user->id,
        ]);

        $this->syncProductQuantity($product);

        return $batch;
    }

    /**
     * Consume `quantity` units of a product, oldest batch first.
     *
     * Writes one stock_movements row per batch touched — one sale line can span
     * several batches. Returns the movements created; their unit_cost values are
     * the true FIFO cost of this consumption.
     *
     * @return Collection<int, StockMovement>
     */
    public function consume(
        Product $product,
        int $quantity,
        string $referenceType,
        int $referenceId,
        ?int $referenceItemId,
        Carbon $occurredAt,
        User $user,
    ): Collection {
        $this->assertInTransaction();

        if ($quantity <= 0) {
            throw new RuntimeException('Cannot consume a non-positive quantity.');
        }

        // Section 5 (Concurrency): lock BEFORE checking. A check outside the lock
        // is worthless — two staff can both read "5 available" and both consume 4.
        //
        // In one canonical order first, so a sale and a return cannot hold one
        // another's rows — see claim(). The select below then reads
        // rows this transaction already owns.
        $this->claim([$product->id]);

        $batches = StockBatch::where('product_id', $product->id)
            ->withStock()
            ->fifoOrder()
            ->lockForUpdate()
            ->get();

        // Re-check availability AFTER acquiring the lock, never before.
        $available = (int) $batches->sum('quantity_remaining');

        if ($available < $quantity) {
            throw new InsufficientStockException($available, $quantity);
        }

        $movements = collect();
        $remaining = $quantity;
        $sequence = $this->nextSequence($referenceType, $referenceId);

        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }

            $take = min($batch->quantity_remaining, $remaining);

            $batch->forceFill([
                'quantity_remaining' => $batch->quantity_remaining - $take,
            ])->save();

            $movements->push(StockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $batch->id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reference_item_id' => $referenceItemId,
                'quantity' => -$take,
                // Copied from the batch, so the cost recorded is the cost that
                // will later be reversed, to the dinar.
                'unit_cost' => $batch->unit_cost,
                'occurred_at' => $occurredAt,
                'sequence' => $sequence++,
                'user_id' => $user->id,
            ]));

            $remaining -= $take;
        }

        $this->syncProductQuantity($product);

        return $movements;
    }

    /**
     * Return units of a sale line to the batches they actually came from.
     *
     * This is the algorithm in Section 5, and the reason `reverses_movement_id`
     * exists. Never re-derive "reverse order" from scratch: a second return has
     * no way to know what the first one already gave back. Track it at the
     * movement level instead.
     *
     * @return Collection<int, StockMovement>
     */
    public function restoreForSaleItem(
        SaleItem $saleItem,
        int $quantity,
        int $saleReturnId,
        int $saleReturnItemId,
        Carbon $occurredAt,
        User $user,
    ): Collection {
        $this->assertInTransaction();

        $product = $saleItem->product;

        // Lock every batch of this product, not just the ones with stock — a
        // return refills batches that have reached 0, and an empty batch is not
        // closed.
        //
        // In ascending id, NOT fifoOrder(): this path and consume() must claim
        // the same rows in the same direction or they deadlock against each
        // other. See claim().
        $this->claim([$product->id]);

        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_item_id', $saleItem->id)
            ->outbound()
            ->orderByDesc('sequence')   // last consumed, first returned
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $created = collect();
        $sequence = $this->nextSequence(StockMovement::REF_SALE_RETURN, $saleReturnId);

        foreach ($movements as $movement) {
            if ($remaining === 0) {
                break;
            }

            // Computed from what was actually given back, so the second, third and
            // fourth partial returns each pick up exactly where the last left off.
            $available = $movement->availableToReverse();

            if ($available === 0) {
                continue;
            }

            $take = min($available, $remaining);

            $batch = StockBatch::whereKey($movement->stock_batch_id)->firstOrFail();

            $batch->forceFill([
                'quantity_remaining' => $batch->quantity_remaining + $take,
            ])->save();

            $created->push(StockMovement::create([
                'product_id' => $product->id,
                'stock_batch_id' => $movement->stock_batch_id,
                'reference_type' => StockMovement::REF_SALE_RETURN,
                'reference_id' => $saleReturnId,
                'reference_item_id' => $saleReturnItemId,
                // The link that makes this exact.
                'reverses_movement_id' => $movement->id,
                'quantity' => $take,
                // Copied from the ORIGINAL movement, guaranteeing the COGS
                // reversal equals the COGS that was recorded.
                'unit_cost' => $movement->unit_cost,
                'occurred_at' => $occurredAt,
                'sequence' => $sequence++,
                'user_id' => $user->id,
            ]));

            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new RuntimeException(
                __('More returned than this line ever consumed.')
            );
        }

        $this->syncProductQuantity($product);

        return $created;
    }

    /**
     * Deduct a purchase return from that purchase's own batch.
     *
     * Section 5: purchase returns are simpler — one purchase_item maps to exactly
     * one batch, so there is no ordering question. You are returning those
     * specific goods to that supplier, not the oldest ones you happen to hold.
     */
    public function deductFromBatch(
        StockBatch $batch,
        int $quantity,
        int $purchaseReturnId,
        int $purchaseReturnItemId,
        Carbon $occurredAt,
        User $user,
    ): StockMovement {
        $this->assertInTransaction();

        // One row, but a single row is still half of a cycle — the other side
        // only has to hold it and want another of the same product. Claimed the
        // same way as every other path first. See claim().
        $this->claim([$batch->product_id]);

        $locked = StockBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

        // Section 7: purchase returns are limited by the batch — you can't send
        // back goods you no longer hold.
        if ($locked->quantity_remaining < $quantity) {
            throw new InsufficientStockException(
                $locked->quantity_remaining,
                $quantity,
                __('Not enough stock from this purchase: :count available.', [
                    'count' => $locked->quantity_remaining,
                ])
            );
        }

        $locked->forceFill([
            'quantity_remaining' => $locked->quantity_remaining - $quantity,
        ])->save();

        $movement = StockMovement::create([
            'product_id' => $locked->product_id,
            'stock_batch_id' => $locked->id,
            'reference_type' => StockMovement::REF_PURCHASE_RETURN,
            'reference_id' => $purchaseReturnId,
            'reference_item_id' => $purchaseReturnItemId,
            'quantity' => -$quantity,
            'unit_cost' => $locked->unit_cost,
            'occurred_at' => $occurredAt,
            'sequence' => $this->nextSequence(StockMovement::REF_PURCHASE_RETURN, $purchaseReturnId),
            'user_id' => $user->id,
        ]);

        $this->syncProductQuantity($locked->product);

        return $movement;
    }

    /**
     * Undo a set of movements, putting each batch back exactly as it was.
     *
     * Section 5: deleting a return is trivial and safe — take its movements,
     * subtract each from its batch, delete them. The reverses_movement_id links
     * restore the earlier state exactly, with no recomputation.
     */
    public function reverseMovements(Collection $movements): void
    {
        $this->assertInTransaction();

        $products = collect();

        /*
         * Every batch row this will touch, claimed in one order before any of
         * it happens — see claim(). The loop below still walks the
         * movements newest-first, which it must, but by then the rows are
         * already held: the ORDER OF THE LOOP stops being a lock order at all.
         * That distinction is the fix. Before it, this walked descending while
         * consume() walked ascending, and a sale meeting a return on the same
         * product was a cycle.
         */
        $this->claim($movements->pluck('product_id'));

        $this->assertStillReversible($movements);

        // Delete the newest first so a movement that something else reverses is
        // never removed while its own reversal still points at it.
        foreach ($movements->sortByDesc('id') as $movement) {
            $batch = StockBatch::whereKey($movement->stock_batch_id)->lockForUpdate()->firstOrFail();

            $batch->forceFill([
                'quantity_remaining' => $batch->quantity_remaining - $movement->quantity,
            ])->save();

            $products->put($movement->product_id, $movement->product_id);

            $movement->delete();
        }

        foreach ($products as $productId) {
            $this->syncProductQuantity(Product::findOrFail($productId));
        }
    }

    /**
     * Section 4: `products.quantity` is a cache, not the truth.
     *
     * Always written as SUM(quantity_remaining), never as quantity +/- n —
     * incremental maths compounds any error permanently, while a recomputed sum
     * is self-correcting. Called inside the same transaction as every movement.
     */
    public function syncProductQuantity(Product $product): int
    {
        $this->assertInTransaction();

        $sum = (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');

        $product->forceFill(['quantity' => $sum])->save();

        return $sum;
    }

    /**
     * Refuse to unwind movements whose units are no longer on the shelf.
     *
     * Section 5 calls deleting a return "trivial and safe", and it is — but only
     * while the units it put back are still in their batch. Section 5 is equally
     * clear that "a batch that reaches 0 is not finished — a return can refill
     * it", so the moment a return lands, its units are available to the next
     * sale. Once that sale happens there is nothing left to take back.
     *
     * Checked up front, across the whole set, so the refusal is all-or-nothing
     * and never leaves half a document unwound.
     */
    private function assertStillReversible(Collection $movements): void
    {
        $needed = $movements
            ->where('quantity', '>', 0)
            ->groupBy('stock_batch_id')
            ->map(fn (Collection $group) => (int) $group->sum('quantity'));

        if ($needed->isEmpty()) {
            return;
        }

        $batches = StockBatch::whereIn('id', $needed->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $short = 0;

        foreach ($needed as $batchId => $quantity) {
            $remaining = (int) ($batches[$batchId]->quantity_remaining ?? 0);

            if ($remaining < $quantity) {
                $short += $quantity - $remaining;
            }
        }

        if ($short > 0) {
            throw new RuntimeException(trans_choice(
                '{1}Cannot undo this: :count unit that came back has since been sold or written off. Return it to stock first, or correct it with a stock adjustment.'
                .'|[2,*]Cannot undo this: :count units that came back have since been sold or written off. Return them to stock first, or correct it with a stock adjustment.',
                $short,
                ['count' => $short],
            ));
        }
    }

    /** The next movement sequence within one document. */
    private function nextSequence(string $referenceType, int $referenceId): int
    {
        return (int) StockMovement::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->max('sequence') + 1;
    }

    /**
     * Take every batch lock this operation needs, in ONE order, before anything else.
     *
     * **Why this exists — measured on MariaDB 10.11, 2026-09-11.**
     *
     * The batch rows were locked in three different orders in this one file:
     * `consume()` took them oldest-first (`fifoOrder()`), `reverseMovements()`
     * newest-first and one at a time, and `restoreForSaleItem()` in whatever
     * order the engine felt like returning a `whereIn`. Any two of those running
     * at once on the same product is a cycle — one transaction holding row A and
     * waiting for row B while the other holds B and waits for A — and InnoDB
     * ends it by killing one of them.
     *
     * Two sales could never show this. A sale takes the SALE counter row inside
     * its own transaction, so **two tills serialise from their first statement**
     * and never contend on a batch at all. A sale and a RETURN take different
     * counter rows — `PREFIX_SALE` and `PREFIX_SALE_RETURN` — so nothing
     * serialises them, and they reach the same shelf in opposite directions.
     * Racing six of them, three were killed:
     *
     *     SQLSTATE[40001]: Serialization failure: 1213 Deadlock found
     *     SQL: select * from stock_batches where product_id = 1
     *          and quantity_remaining > 0
     *          order by received_at asc, sequence asc, id asc for update
     *
     * A deadlock does not corrupt anything — InnoDB rolls its victim back whole,
     * which is why the ledger stayed exactly right and every test stayed green.
     * What the shopkeeper gets is a sale that fails with a 500, at random, only
     * when the shop is busy: the hardest kind of fault to report and the easiest
     * to disbelieve.
     *
     * So every path claims the same rows in the same order first — ascending
     * `id`, which every row has and nothing re-dates. FIFO order is NOT usable
     * for this: it sorts by `received_at`, and a back-dated delivery puts a new
     * row in the middle of the sequence. After this call the rows are already
     * held, so the FIFO select that follows introduces no new lock order — it
     * reads rows this transaction already owns.
     *
     * Locking every batch of the product rather than only the ones with stock is
     * deliberate: "which rows have stock" is itself a moving target, and two
     * transactions disagreeing about the set to lock is the same fault wearing a
     * different hat.
     *
     * **The second measurement, and why the products table is here too.**
     *
     * Ordering the batch rows alone moved the cycle rather than closing it. The
     * `products` row is a lock target in its own right: `syncProductQuantity`
     * UPDATEs it, and every `sale_items` or `sale_return_items` insert takes a
     * foreign-key lock on it on the way past. So a sale held products and wanted
     * batches while a return held batches and wanted products — the same cycle,
     * one table along:
     *
     *     selling:   select * from stock_batches where product_id in (1)
     *                order by id asc for update
     *     returning: insert into sale_return_items (...)
     *
     * Hence both tables, always the same way round: products first, then their
     * batches, each ascending by id. And hence PUBLIC, called at the TOP of the
     * transaction rather than partway down inside `consume()` — a sale inserts
     * its lines, and takes that foreign-key lock, before it ever reaches the
     * stock, so a claim made after the first insert is already too late.
     *
     * @param  array<int, int>|Collection<int, int>  $productIds
     */
    public function claim($productIds): void
    {
        $this->assertInTransaction();

        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return;
        }

        // The parent rows first: every line insert references one, and
        // syncProductQuantity writes to it at the end of the same transaction.
        Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        StockBatch::whereIn('product_id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'FIFO operations must run inside a transaction so the batch locks hold '.
                'and products.quantity is recalculated atomically with the movement.'
            );
        }
    }
}
