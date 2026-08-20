<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 4: manual +/- with a reason. The ONLY way to correct a locked
 * document, so it must exist before go-live.
 *
 * Adjustments never touch supplier or customer balances — stock moves, money
 * does not.
 */
class StockAdjustmentService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
    ) {}

    public function create(
        Product $product,
        string $direction,
        int $quantity,
        string $reason,
        User $user,
        ?int $unitCost = null,
        ?string $notes = null,
        ?Carbon $adjustedAt = null,
    ): StockAdjustment {
        if ($quantity <= 0) {
            throw new RuntimeException(__('An adjustment needs a quantity above zero.'));
        }

        $adjustedAt ??= now();

        if (books_closed_on($adjustedAt)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        // Section 4: FIFO needs a cost for every unit, so unit_cost cannot be
        // blank on the way in. On the way out the cost comes from the batches
        // consumed, which is the whole point — the value written off is the true
        // FIFO cost.
        if ($direction === StockAdjustment::DIRECTION_IN && ($unitCost === null || $unitCost < 0)) {
            throw new RuntimeException(__('An incoming adjustment needs a unit cost.'));
        }

        return DB::transaction(function () use (
            $product, $direction, $quantity, $reason, $user, $unitCost, $notes, $adjustedAt
        ) {
            $adjustment = StockAdjustment::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_ADJUSTMENT),
                'product_id' => $product->id,
                'user_id' => $user->id,
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $direction === StockAdjustment::DIRECTION_IN ? $unitCost : null,
                'reason' => $reason,
                'notes' => $notes,
                'adjusted_at' => $adjustedAt,
            ]);

            if ($direction === StockAdjustment::DIRECTION_IN) {
                // Creates a new batch with source_type = 'adjustment' and
                // purchase_item_id null.
                $this->fifo->createBatch(
                    product: $product,
                    sourceType: StockBatch::SOURCE_ADJUSTMENT,
                    sourceId: $adjustment->id,
                    unitCost: $unitCost,
                    quantity: $quantity,
                    receivedAt: $adjustedAt,
                    sequence: 1,
                    user: $user,
                );
            } else {
                // Consumes batches FIFO, so the value written off is the true
                // FIFO cost. Blocked if stock is insufficient.
                $this->fifo->consume(
                    product: $product,
                    quantity: $quantity,
                    referenceType: StockMovement::REF_ADJUSTMENT,
                    referenceId: $adjustment->id,
                    referenceItemId: null,
                    occurredAt: $adjustedAt,
                    user: $user,
                );
            }

            return $adjustment->refresh();
        });
    }

    /**
     * Section 5: products already in the shop need a starting batch — quantity
     * plus its cost — or FIFO has no first layer.
     *
     * The doc allows only `purchase` and `adjustment` as a batch source, so
     * opening stock is an `in` adjustment. It has no dedicated reason in the
     * doc's list, so it is recorded as `other` with a note (see Section 13).
     */
    public function recordOpeningStock(
        Product $product,
        int $quantity,
        int $unitCost,
        User $user,
        ?Carbon $adjustedAt = null,
    ): StockAdjustment {
        return $this->create(
            product: $product,
            direction: StockAdjustment::DIRECTION_IN,
            quantity: $quantity,
            reason: 'other',
            user: $user,
            unitCost: $unitCost,
            notes: __('Opening stock'),
            adjustedAt: $adjustedAt,
        );
    }
}
