<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
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

        // There is no stock behind a service to be miscounted, damaged or
        // stolen, so there is nothing here to correct.
        if (! $product->tracksStock()) {
            throw new RuntimeException(__('A service holds no stock, so there is nothing to adjust.'));
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

            $this->apply($adjustment, $product, $user);

            return $adjustment->refresh();
        });
    }

    /**
     * Change an adjustment that was written down wrong.
     *
     * Section 8's shape for an edit, the one purchases and sales already use:
     * reverse what the document did, then apply it again with the new figures,
     * inside one transaction. Nothing is recomputed from the difference — a
     * count that goes from 5 to 3 is not "take two back", it is the whole
     * original undone and a new one of three put in its place, so the batches
     * end up exactly as they would have if it had been right the first time.
     *
     * The product is not part of it. An adjustment is a note about one product's
     * shelf; pointing it at a different product is two documents, not an edit,
     * and the screen does not offer it.
     *
     * Refused for the same reason a delete is refused: an incoming adjustment
     * whose units have since been sold cannot be unwound, because those units
     * are on a customer's invoice.
     */
    public function update(
        StockAdjustment $adjustment,
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

        $adjustedAt ??= $adjustment->adjusted_at;

        // Both dates: the day it was on and the day it is moving to. Editing a
        // document out of a closed period is as much a change to closed books
        // as editing one into it.
        foreach ([$adjustment->adjusted_at, $adjustedAt] as $date) {
            if (books_closed_on($date)) {
                throw new RuntimeException(__('Locked: this date is in a closed period.'));
            }
        }

        if ($direction === StockAdjustment::DIRECTION_IN && ($unitCost === null || $unitCost < 0)) {
            throw new RuntimeException(__('An incoming adjustment needs a unit cost.'));
        }

        return DB::transaction(function () use (
            $adjustment, $direction, $quantity, $reason, $user, $unitCost, $notes, $adjustedAt
        ) {
            $product = $adjustment->product()->firstOrFail();

            $this->unwind($adjustment);

            $adjustment->forceFill([
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $direction === StockAdjustment::DIRECTION_IN ? $unitCost : null,
                'reason' => $reason,
                'notes' => $notes,
                'adjusted_at' => $adjustedAt,
            ])->save();

            $this->apply($adjustment, $product, $user);

            $this->fifo->syncProductQuantity($product->refresh());

            return $adjustment->refresh();
        });
    }

    /**
     * Put an adjustment's figures onto the shelf.
     *
     * Shared by create and update so the two can never drift: whatever an
     * adjustment does to the batches, it does here and nowhere else.
     */
    private function apply(StockAdjustment $adjustment, Product $product, User $user): void
    {
        if ($adjustment->direction === StockAdjustment::DIRECTION_IN) {
            // Creates a new batch with source_type = 'adjustment' and
            // purchase_item_id null.
            $this->fifo->createBatch(
                product: $product,
                sourceType: StockBatch::SOURCE_ADJUSTMENT,
                sourceId: $adjustment->id,
                unitCost: $adjustment->unit_cost,
                quantity: $adjustment->quantity,
                receivedAt: $adjustment->adjusted_at,
                sequence: 1,
                user: $user,
            );

            return;
        }

        // Consumes batches FIFO, so the value written off is the true
        // FIFO cost. Blocked if stock is insufficient.
        $this->fifo->consume(
            product: $product,
            quantity: $adjustment->quantity,
            referenceType: StockMovement::REF_ADJUSTMENT,
            referenceId: $adjustment->id,
            referenceItemId: null,
            occurredAt: $adjustment->adjusted_at,
            user: $user,
        );
    }

    /**
     * Take an adjustment's figures back off the shelf, exactly.
     *
     * The movements say which batches the units came from and how many, so
     * nothing is recomputed and nothing is guessed. Shared by delete and update:
     * an edit begins by undoing the original as completely as a delete would.
     */
    private function unwind(StockAdjustment $adjustment): void
    {
        $movements = StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->where('reference_id', $adjustment->id)
            ->lockForUpdate()
            ->get();

        $this->fifo->reverseMovements($movements);

        // The batch an incoming one opened goes with it. Nothing ever drew
        // on it — the reversal above would have refused — so this removes an
        // empty layer rather than stock.
        StockBatch::where('source_type', StockBatch::SOURCE_ADJUSTMENT)
            ->where('source_id', $adjustment->id)
            ->delete();
    }

    /**
     * Undo an adjustment.
     *
     * Section 8b: a delete is a reversal plus a hidden record, never a way to
     * skip the reversal — so the units go back exactly where they came from.
     * The movements say which batches those were and how many, so nothing is
     * recomputed and nothing is guessed.
     *
     * An outgoing one is always safe to undo: putting units back on a shelf
     * takes nothing from anybody. An incoming one opened a batch, and the engine
     * refuses if anything has since been sold out of it — those units are on a
     * customer's invoice now, and taking them back would leave that sale costed
     * against stock that never existed.
     */
    public function delete(StockAdjustment $adjustment, User $user): void
    {
        if (books_closed_on($adjustment->adjusted_at)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        DB::transaction(function () use ($adjustment, $user) {
            $this->unwind($adjustment);

            $this->fifo->syncProductQuantity($adjustment->product()->firstOrFail());

            app(ActivityLogger::class)->logModel(
                'delete',
                $adjustment,
                __('Deleted adjustment :document', ['document' => $adjustment->document_no]),
            );

            $adjustment->delete();
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
