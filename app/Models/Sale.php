<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['document_no', 'customer_id', 'user_id', 'total_amount', 'status', 'sale_date'])]
class Sale extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PARTLY_RETURNED = 'partly_returned';

    public const STATUS_RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'sale_date' => 'date',
        ];
    }

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
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable', 'payable_type', 'payable_id');
    }

    public function amountPaid(): int
    {
        return (int) $this->payments()->where('direction', Payment::DIRECTION_IN)->sum('amount');
    }

    public function amountDue(): int
    {
        return $this->total_amount - $this->amountPaid();
    }

    /**
     * Section 4: status is derived, never typed. Recomputed from the lines inside
     * the same transaction as any return — and again if a return is deleted, so a
     * sale can go back from `returned` to `partly_returned`.
     *
     * The sale row itself is never voided or deleted. The sale happened, the
     * return happened, and both stay visible.
     */
    public function recalculateStatus(): string
    {
        $sold = (int) $this->items()->sum('quantity');
        $returned = (int) $this->items()->sum('quantity_returned');

        $status = match (true) {
            $returned <= 0 => self::STATUS_ACTIVE,
            $returned >= $sold => self::STATUS_RETURNED,
            default => self::STATUS_PARTLY_RETURNED,
        };

        $this->forceFill(['status' => $status])->save();

        return $status;
    }

    /**
     * Section 8: the lock rules, in ONE place.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBeModified(?User $user = null): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason];

        // 1. Within 24 hours.
        if ($this->created_at->lt(now()->subDay())) {
            return $deny(__('Locked: more than 24 hours old.'));
        }

        // 2. No sale returns against it.
        if ($this->returns()->exists()) {
            return $deny(__('Locked: this sale has a return against it. Delete the return first.'));
        }

        // 3. No LATER stock movement for any product in this sale.
        //
        //    Why: if this sale took 5 units from Batch 1 and a later sale took the
        //    rest, editing this one up to 8 would spill into Batch 2 at a different
        //    cost while the later sale holds the cheaper units. FIFO order inverts
        //    and both are wrong.
        $productIds = $this->items()->pluck('product_id')->unique();

        //    "Later" means later in the movement timeline, which is the order
        //    FIFO actually runs in — not later than the row was created.
        //    occurred_at carries microseconds and created_at does not, so
        //    comparing the two reads a purchase made in the same second as this
        //    sale as coming after it, and locks a sale nothing has touched.
        $takenAt = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $this->id)
            ->max('occurred_at') ?? $this->created_at;

        $laterMovement = StockMovement::whereIn('product_id', $productIds)
            ->where(function ($q) {
                $q->where('reference_type', '!=', StockMovement::REF_SALE)
                    ->orWhere('reference_id', '!=', $this->id);
            })
            ->where('occurred_at', '>', $takenAt)
            ->exists();

        if ($laterMovement) {
            return $deny(__('Locked: stock for a product in this sale has moved since. Editing it now would invert FIFO order.'));
        }

        // 4. New grand total >= amount already paid is checked at save time.

        // 5. Not in a closed period.
        if (books_closed_on($this->sale_date)) {
            return $deny(__('Locked: this date is in a closed period.'));
        }

        // 6. User is admin, or has the sales.edit permission.
        if ($user && ! $user->hasPermission('sales.edit')) {
            return $deny(__('You do not have permission to edit sales.'));
        }

        return ['allowed' => true, 'reason' => null];
    }
}
