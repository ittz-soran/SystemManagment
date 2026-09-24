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

        /*
         * ⚠️ All of them came from a purchase, or none of them did.
         *
         * A mixed line is the one case this document cannot tell the truth
         * about: the units that have a supplier must go back into their batch
         * so the purchase return can take them, and the units that have none
         * must not, or they sit on the shelf as sellable stock while being
         * broken. Restoring a chosen subset would need FIFO to put back
         * particular units rather than a count of them, which is surgery on the
         * one class in this system that must never be wrong.
         *
         * So it refuses, and says where to go instead. Rare enough to be worth
         * a sentence rather than a second mechanism: it needs one invoice line
         * whose units came partly from a purchase and partly from opening stock
         * or another room.
         */
        $origins = $this->returns->originsFor($saleItem, $quantity);
        $fromPurchase = $origins->filter(fn ($origin) => $origin->purchase_item !== null)->sum('quantity');

        if ($fromPurchase > 0 && $fromPurchase < $origins->sum('quantity')) {
            throw new RuntimeException(__('Some of these units came from a purchase and some did not, so they cannot be swapped together. Take it back on the invoice instead.'));
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
             * 1. The faulty unit goes back into its own batch — but ONLY if
             * there is a supplier waiting to take it off that batch again.
             *
             * ⚠️ **Found 2026-09-24, two days after this shipped, by a test
             * written for the delete button.** A unit with no purchase behind
             * it was put back and then left there: the shelf counted a broken
             * power bank as sellable, and the document said the swap had cost
             * nothing while the shop had given away a good one. The comment
             * here used to say the restore was needed "because that is the only
             * thing a purchase return will accept" — which is exactly the
             * reason it must not happen when there is no purchase return.
             *
             * Not restored, the unit simply never comes back: the sale already
             * costed it, the shelf stays right, `faulty_cost` stays zero, and
             * `cost()` then reads the whole replacement — which is the truth of
             * what the shop is out of pocket.
             */
            $canGoBack = $origins->filter(fn ($origin) => $origin->purchase_item !== null)->sum('quantity');

            if ($canGoBack > 0) {
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
            }

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
     * Undo the whole thing — Soran, 2026-09-24.
     *
     * ⚠️ **Backwards through exactly what create() did, and the order is the
     * whole of it.** The supplier is un-billed FIRST, because that is what puts
     * the faulty unit back into its batch — and the movement removed second is
     * the one that put it there, which cannot come off a batch that has not got
     * it.
     *
     * ⚠️ Reversed, it refuses with "not enough in the batch" on a swap that is
     * perfectly undoable — but ONLY when that batch has been emptied, which is
     * why an ordinary fixture cannot see the difference. The test that guards
     * this makes the faulty unit the last of its own batch and takes the
     * replacement off another one; a first version with stock to spare passed
     * the sabotage and proved nothing.
     *
     * ⚠️ **A swap deleted is a swap that never happened, on the books.** The
     * customer keeps whatever they walked out with; this is for a swap recorded
     * in error, not for one the shop changed its mind about. What comes back is
     * the shelf, the supplier's balance and the invoice line's right to be
     * returned.
     */
    public function delete(Swap $swap, User $user): void
    {
        $state = $swap->canBeDeleted($user);

        if (! $state['allowed']) {
            throw new RuntimeException($state['reason']);
        }

        DB::transaction(function () use ($swap, $user) {
            // Section 8: asked again inside the transaction. Between the page
            // loading and this running somebody may have closed the books, or
            // sold the very unit this is about to take back.
            $state = $swap->fresh()->canBeDeleted($user);

            if (! $state['allowed']) {
                throw new RuntimeException($state['reason']);
            }

            /*
             * 1. The supplier is un-billed, and the faulty unit lands back in
             *    its batch. `alreadyAuthorised` because the right to do this
             *    came from `swaps.delete` — see PurchaseReturnService::delete.
             */
            $return = $swap->purchaseReturn()->first();

            if ($return !== null) {
                $this->purchaseReturns->delete($return, $user, alreadyAuthorised: true);
            }

            /*
             * 2. Both of the swap's own movements come off: the faulty unit out
             *    of its batch again, and the replacement back onto the shelf.
             *    One call, so it is one all-or-nothing check.
             */
            $movements = StockMovement::where('reference_type', StockMovement::REF_SWAP)
                ->where('reference_id', $swap->id)
                ->lockForUpdate()
                ->get();

            $this->fifo->reverseMovements($movements);

            /*
             * 3. And the invoice line may be given back again. ⚠️ Locked and
             *    read fresh: a stale model here writes nothing at all, because
             *    forceFill on an instance that already holds the value leaves
             *    the row clean and save() does no work.
             */
            $saleItem = SaleItem::whereKey($swap->sale_item_id)->lockForUpdate()->firstOrFail();

            $saleItem->forceFill([
                'quantity_swapped' => $saleItem->quantity_swapped - $swap->quantity,
            ])->save();

            $swap->delete();
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
