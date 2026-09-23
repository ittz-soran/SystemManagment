<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Swap;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A faulty item swapped for the same thing — Soran, 2026-09-23.
 *
 * *"if I have same product I change for him and back this faulty PD-17-UK to
 * supplier and refund, not change inv lines"*.
 *
 * ⚠️ **THE INVOICE IS NOT TOUCHED.** The customer bought one power bank for
 * 60,000 and still owns one; the invoice is still true, word for word. What
 * changed is which physical unit they have, and what is on the shop's shelf.
 *
 * ⚠️ **AND THE FAULTY UNIT IS ALREADY OUT OF STOCK** — it left when it was
 * sold. So this moves stock once for the replacement, and once more only to
 * hand the faulty unit back to the supplier it came from.
 */
class SwapService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private SaleReturnService $returns,
        private PurchaseReturnService $purchaseReturns,
    ) {}

    /**
     * Give the customer the same thing again, and send the faulty one back.
     *
     * @param  int  $quantity  how many of that line came back faulty
     */
    public function create(
        SaleItem $saleItem,
        int $quantity,
        User $user,
        ?Carbon $swappedAt = null,
        ?string $note = null,
    ): Swap {
        $swappedAt ??= now();

        if ($quantity < 1) {
            throw new RuntimeException(__('Swap at least one.'));
        }

        if ($quantity > $saleItem->returnableQuantity()) {
            throw new RuntimeException(__('Only :count of that line can still come back.', [
                'count' => $saleItem->returnableQuantity(),
            ]));
        }

        if (books_closed_on($swappedAt)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        $product = $saleItem->product()->firstOrFail();

        if (! $product->tracksStock()) {
            throw new RuntimeException(__('A service cannot be swapped — there is nothing to hand over.'));
        }

        /*
         * ⚠️ Checked before anything is written, and checked against the SHELF
         * rather than the whole shop. A swap the till cannot complete is worse
         * than one it refuses: the customer is standing there.
         */
        if ($product->quantity < $quantity) {
            throw new RuntimeException(__('There is no :product left to swap it for. Return it or change it for something else.', [
                'product' => $product->name,
            ]));
        }

        return DB::transaction(function () use ($saleItem, $quantity, $user, $swappedAt, $note, $product) {
            $swap = new Swap([
                'sale_id' => $saleItem->sale_id,
                'sale_item_id' => $saleItem->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'note' => $note,
                'swapped_at' => $swappedAt,
            ]);

            $swap->document_no = $this->numbers->next(DocumentNumberService::PREFIX_SWAP);
            $swap->user_id = $user->id;
            // Written below, once the batches have said what they cost.
            $swap->replacement_cost = 0;
            $swap->faulty_cost = 0;
            $swap->save();

            /*
             * ⚠️ Where the faulty unit came from, read BEFORE it is put back —
             * the same trace the faulty sale return uses, in the same order the
             * restore will use, so the supplier billed is the supplier whose
             * batch receives it.
             */
            $origins = $this->returns->originsFor($saleItem, $quantity);

            /*
             * 1. The faulty unit goes back into its own batch.
             *
             * Not to be sold — it goes straight out again to the supplier
             * below. It has to pass through its batch because that is the only
             * thing a purchase return will accept, and because the movement
             * then says exactly which units these were.
             */
            $restored = $this->fifo->restoreForSaleItem(
                saleItem: $saleItem,
                quantity: $quantity,
                saleReturnId: $swap->id,
                saleReturnItemId: $swap->id,
                occurredAt: $swappedAt,
                user: $user,
                referenceType: StockMovement::REF_SWAP,
            );

            $swap->faulty_cost = (int) $restored->sum(fn ($movement) => $movement->quantity * $movement->unit_cost);

            // 2. Sent back to the supplier it was bought from.
            $swap->purchase_return_id = $this->sendBack($origins, $swap, $user, $swappedAt);

            /*
             * 3. The replacement leaves the shelf, FIFO, and the customer walks
             * out with it. ⚠️ AFTER the faulty unit has gone back to the
             * supplier, so the replacement cannot be the very unit that was
             * just handed over — which it would be if this ran first and FIFO
             * reached for the oldest layer.
             */
            $out = $this->fifo->consume(
                product: $product->refresh(),
                quantity: $quantity,
                referenceType: StockMovement::REF_SWAP,
                referenceId: $swap->id,
                referenceItemId: null,
                occurredAt: $swappedAt,
                user: $user,
            );

            $swap->replacement_cost = (int) $out->sum(fn ($movement) => -$movement->quantity * $movement->unit_cost);
            $swap->save();

            /*
             * ⚠️ The line is not edited — same quantity, same price, same
             * printed invoice — but a unit already handed back must not ALSO be
             * returnable, or a second unit that never existed would go onto the
             * shelf.
             */
            $saleItem->forceFill([
                'quantity_swapped' => $saleItem->quantity_swapped + $quantity,
            ])->save();

            return $swap->refresh();
        });
    }

    /**
     * Raise one purchase return for the faulty units, per purchase drawn on.
     *
     * ⚠️ Null when nothing can be sent back: opening stock and anything carried
     * in from another room has no purchase behind it. The swap still happens —
     * the customer is served either way — and the shop simply carries the cost,
     * which the document then shows.
     *
     * @param  Collection<int, object>  $origins
     */
    private function sendBack($origins, Swap $swap, User $user, Carbon $swappedAt): ?int
    {
        $byPurchase = [];

        foreach ($origins as $origin) {
            if ($origin->purchase_item === null) {
                continue;
            }

            $purchaseId = $origin->purchase_item->purchase_id;
            $itemId = $origin->purchase_item->id;

            $byPurchase[$purchaseId][$itemId] = ($byPurchase[$purchaseId][$itemId] ?? 0) + $origin->quantity;
        }

        $first = null;

        foreach ($byPurchase as $purchaseId => $quantities) {
            $return = $this->purchaseReturns->create(
                purchase: Purchase::findOrFail($purchaseId),
                lines: collect($quantities)
                    ->map(fn (int $quantity, int $itemId) => [
                        'purchase_item_id' => $itemId,
                        'quantity' => $quantity,
                    ])->values()->all(),
                user: $user,
                returnDate: $swappedAt,
                reason: __('Faulty, swapped for the customer on :document', [
                    'document' => $swap->document_no,
                ]),
            );

            $first ??= $return->id;
        }

        return $first;
    }
}
