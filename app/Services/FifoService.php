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
        StockBatch::where('product_id', $product->id)
            ->fifoOrder()
            ->lockForUpdate()
            ->get();

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

    /** The next movement sequence within one document. */
    private function nextSequence(string $referenceType, int $referenceId): int
    {
        return (int) StockMovement::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->max('sequence') + 1;
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
