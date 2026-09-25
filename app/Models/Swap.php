<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A faulty item handed back and replaced with the same thing — Soran, 2026-09-23.
 *
 * ⚠️ The invoice it points at is NOT changed. The customer bought one and still
 * owns one; what changed is which physical unit, and the shop's shelf.
 */
#[Fillable(['sale_id', 'sale_item_id', 'product_id', 'quantity', 'note', 'swapped_at'])]
class Swap extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'replacement_cost' => 'integer',
            'faulty_cost' => 'integer',
            'swapped_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this can be undone, and what to say when it cannot.
     *
     * Section 8: computed live and re-checked inside the transaction. The
     * screen uses it to disable the button and print the reason rather than
     * letting the attempt fail after the fact.
     *
     * Undoing a swap runs backwards through what making one did: the supplier
     * is un-billed, which puts the faulty unit back into its batch; then both
     * swap movements come off, which takes that unit out again and puts the
     * replacement back on the shelf.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function canBeDeleted(?User $user = null): array
    {
        return $this->canBeUndone($user, 'swaps.delete',
            __('You do not have permission to delete swaps.'));
    }

    /**
     * Can how many were handed over still be corrected? — Soran, 2026-09-25.
     *
     * ⚠️ **The same mechanical question as deleting, because it IS a deletion**
     * — `update()` unwinds the whole swap and lays it down again at the new
     * figure. If the faulty units cannot come back out of their batch, the
     * quantity cannot be changed either, and for exactly the same reason.
     *
     * ⚠️ **Two keys, and no existing permission quietly widened.** `swaps.edit`
     * was sold to shops as *"correct the note on a swap"*, and a shopkeeper who
     * granted it granted that. Changing a quantity un-bills a supplier and
     * moves stock twice, so it asks for `swaps.delete` as well — the key that
     * already means "you may undo one of these". Nobody's access changes
     * because this exists; the button simply is not there without both.
     */
    public function canBeChanged(?User $user = null): array
    {
        if ($user && ! $user->hasPermission('swaps.edit')) {
            return ['allowed' => false, 'reason' => __('You do not have permission to correct swaps.')];
        }

        return $this->canBeUndone($user, 'swaps.delete',
            __('Changing how many were handed over undoes the swap and does it again, so it needs the same permission as deleting one.'));
    }

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    private function canBeUndone(?User $user, string $permission, string $refusal): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason];

        if (books_closed_on($this->swapped_at)) {
            return $deny(__('Locked: this date is in a closed period.'));
        }

        if ($user && ! $user->hasPermission($permission)) {
            return $deny($refusal);
        }

        $return = $this->purchaseReturn()->first();

        if ($return !== null) {
            /*
             * ⚠️ Asked WITHOUT the user, deliberately. This is the purchase
             * return's mechanical question — is the batch still able to take
             * these units back — and not its permission question. Making a swap
             * bills a supplier under `swaps.create` without anybody holding
             * `purchase_returns.create`; undoing one un-bills them under
             * `swaps.delete` by the same rule. Both sides of that power live
             * together or a shop can hand out the doing and withhold the
             * undoing.
             */
            $state = $return->canBeDeleted();

            if (! $state['allowed']) {
                return $deny($state['reason']);
            }

            /*
             * And nothing else to check. That return takes back exactly the
             * units this swap put into the batch, from that same batch, so once
             * it is undone the faulty unit is certain to be there for the
             * movement below to remove again.
             */
            return ['allowed' => true, 'reason' => null];
        }

        /*
         * ⚠️ No purchase return: either none was ever raised — the unit did not
         * come from a purchase — or somebody has already deleted it by hand
         * from the purchase-returns screen. In the second case the faulty unit
         * has been sitting in its batch ever since and may have been sold to
         * somebody else, and there would be nothing left to take back.
         */
        $needed = StockMovement::where('reference_type', StockMovement::REF_SWAP)
            ->where('reference_id', $this->id)
            ->where('quantity', '>', 0)
            ->selectRaw('stock_batch_id, SUM(quantity) as needed')
            ->groupBy('stock_batch_id')
            ->pluck('needed', 'stock_batch_id');

        $short = 0;

        foreach ($needed as $batchId => $quantity) {
            $remaining = (int) StockBatch::whereKey($batchId)->value('quantity_remaining');
            $short += max(0, (int) $quantity - $remaining);
        }

        if ($short > 0) {
            return $deny(trans_choice(
                '{1}:count faulty unit has since been sold or written off, so this swap can no longer be undone.'
                .'|[2,*]:count faulty units have since been sold or written off, so this swap can no longer be undone.',
                $short,
                ['count' => $short],
            ));
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * What the swap cost the shop.
     *
     * The replacement came out of a batch that may have cost more than the
     * faulty one did, and the supplier only gives back what they were paid.
     * ⚠️ Positive means the shop is out of pocket, which is the ordinary case
     * when prices have risen since.
     */
    public function cost(): int
    {
        return $this->replacement_cost - $this->faulty_cost;
    }
}
