<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Models\Concerns\HidesArchivedPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'document_no', 'supplier_id', 'user_id', 'supplier_invoice_no',
    'total_amount', 'discount_amount', 'grand_total', 'status',
    'exchange_rate', 'purchase_date',
])]
class Purchase extends Model
{
    use HidesArchivedPeriod, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PARTLY_RETURNED = 'partly_returned';

    public const STATUS_RETURNED = 'returned';

    /** Section 8c: the column an archived period is decided by. */
    public function archivePeriodColumn(): string
    {
        return 'purchase_date';
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'discount_amount' => 'integer',
            'grand_total' => 'integer',
            'exchange_rate' => 'integer',
            'purchase_date' => 'date',
        ];
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
        return $this->hasMany(PurchaseItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable', 'payable_type', 'payable_id');
    }

    public function batches()
    {
        return StockBatch::where('source_type', StockBatch::SOURCE_PURCHASE)
            ->where('source_id', $this->id);
    }

    /**
     * What has been paid to the supplier so far.
     *
     * Section 4 gives the amount-due formula as
     * `grand_total - SUM(amount WHERE direction = 'in')`, but that sentence
     * describes the sale side. The same section also says `direction = out` is
     * "money leaving the till — a cash refund to a customer, or paying a
     * supplier", so on a purchase the settling payments are the outbound ones.
     * Reading `in` here would report every supplier payment as cash arriving.
     */
    public function amountPaid(): int
    {
        return (int) $this->payments()->where('direction', Payment::DIRECTION_OUT)->sum('amount');
    }

    public function amountDue(): int
    {
        return $this->grand_total - $this->amountPaid();
    }

    /**
     * Section 8: the lock rules, in ONE place. Controllers call this and
     * re-check inside the transaction; views call it to show buttons and reasons.
     * Never duplicate these conditions.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBeModified(?User $user = null): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason];

        // 1. Within 24 hours of creation.
        if ($this->created_at->lt(now()->subDay())) {
            return $deny(__('Locked: more than 24 hours old.'));
        }

        // 2. Every batch untouched — quantity_remaining == quantity_in.
        $consumed = (int) $this->batches()->sum('quantity_in')
            - (int) $this->batches()->sum('quantity_remaining');

        if ($consumed > 0) {
            return $deny(trans_choice(
                '{1}Locked: :count unit from this purchase has already been used.'
                .'|[2,*]Locked: :count units from this purchase have already been used.',
                $consumed, ['count' => $consumed],
            ));
        }

        // 3. No purchase returns against it.
        if ($this->returns()->exists()) {
            return $deny(__('Locked: this purchase has a return against it. Delete the return first.'));
        }

        // 4. New grand total >= amount already paid is checked at save time by the
        //    caller, which is the only place the new total is known.

        // 5. Not in a closed period.
        if (books_closed_on($this->purchase_date)) {
            return $deny(__('Locked: this date is in a closed period.'));
        }

        // 6. User is admin, or has the purchases.edit permission.
        if ($user && ! $user->hasPermission('purchases.edit')) {
            return $deny(__('You do not have permission to edit purchases.'));
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Section 8: delete additionally requires that no stock_movements row has EVER
     * referenced its batches — even ones since cancelled by a return, because those
     * rows would be orphaned.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canBeDeleted(?User $user = null): array
    {
        $modify = $this->canBeModified($user);

        if (! $modify['allowed']) {
            return $modify;
        }

        $everUsed = StockMovement::whereIn('stock_batch_id', $this->batches()->select('id'))
            ->where('reference_type', '!=', StockMovement::REF_PURCHASE)
            ->exists();

        if ($everUsed) {
            return [
                'allowed' => false,
                'reason' => __('This purchase has been used in a sale. You can edit it, but not delete it.'),
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
