<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'document_no', 'purchase_id', 'supplier_id', 'user_id',
    'total_amount', 'return_date', 'reason',
])]
class PurchaseReturn extends Model
{
    use HidesArchivedPeriod, SoftDeletes;

    /** Section 8c: the column an archived period is decided by. */
    public function archivePeriodColumn(): string
    {
        return 'return_date';
    }

    /**
     * Section 8: the conditions, in one place, for undoing a return to a supplier.
     *
     * Simpler than undoing a sale return, and worth saying why. A sale return
     * PUTS units back on the shelf, so undoing it has to take them away again —
     * and they may already have been sold to somebody else, which is the case
     * that refuses. A purchase return SENDS units away, so undoing it puts them
     * back: nobody else can have taken units that were not there.
     *
     * What is left is the period, the permission, and the arithmetic.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBeDeleted(?User $user = null): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason];

        if (books_closed_on($this->return_date)) {
            return $deny(__('Locked: this date is in a closed period.'));
        }

        if ($user && ! $user->hasPermission('purchase_returns.delete')) {
            return $deny(__('You do not have permission to delete returns.'));
        }

        // A batch can never hold more than it was bought with. Nothing in the
        // system should be able to breach this, which is exactly why it is
        // worth checking before writing rather than discovering afterwards.
        $movements = StockMovement::where('reference_type', StockMovement::REF_PURCHASE_RETURN)
            ->where('reference_id', $this->id)
            ->where('quantity', '<', 0)
            ->selectRaw('stock_batch_id, SUM(quantity) as moved')
            ->groupBy('stock_batch_id')
            ->pluck('moved', 'stock_batch_id');

        foreach ($movements as $batchId => $moved) {
            $batch = StockBatch::find($batchId);

            if ($batch === null) {
                return $deny(__('The batch these goods came from is no longer there, so this return cannot be undone.'));
            }

            if ($batch->quantity_remaining + abs((int) $moved) > $batch->quantity_in) {
                return $deny(__('Putting these goods back would leave the batch holding more than was bought. Correct it with a stock adjustment instead.'));
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'return_date' => 'date'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable', 'payable_type', 'payable_id');
    }
}
