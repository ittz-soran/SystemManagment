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
    'document_no', 'sale_id', 'customer_id', 'user_id',
    'total_amount', 'return_date', 'reason',
])]
class SaleReturn extends Model
{
    use HidesArchivedPeriod, SoftDeletes;

    /** Section 8c: the column an archived period is decided by. */
    public function archivePeriodColumn(): string
    {
        return 'return_date';
    }

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'return_date' => 'date'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** Copied from the sale for fast reporting. The sale is the source of truth. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable', 'payable_type', 'payable_id');
    }

    /**
     * Whether this return can still be unwound.
     *
     * Section 5 says deleting a return is safe because its movements simply go
     * back to their batches — but only while those units are still there. A
     * return refills a batch, and Section 5 is explicit that "a batch that
     * reaches 0 is not finished — a return can refill it", so the units are on
     * the shelf the moment the return lands. Once they sell again there is
     * nothing to take back.
     *
     * Computed live, never stored, exactly like the Section 8 locks.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBeDeleted(?User $user = null): array
    {
        if (books_closed_on($this->return_date)) {
            return ['allowed' => false, 'reason' => __('Locked: this date is in a closed period.')];
        }

        if ($user && ! $user->hasPermission('sale_returns.delete')) {
            return ['allowed' => false, 'reason' => __('You do not have permission to delete returns.')];
        }

        $needed = StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
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
            return [
                'allowed' => false,
                'reason' => trans_choice(
                    '{1}:count unit that came back has since been sold or written off, so this return can no longer be undone.'
                    .'|[2,*]:count units that came back have since been sold or written off, so this return can no longer be undone.',
                    $short,
                    ['count' => $short],
                ),
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
