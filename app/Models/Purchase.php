<?php

namespace App\Models;

use App\Models\Concerns\CreditedByReturns;
use App\Models\Concerns\HidesArchivedPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'document_no', 'supplier_id', 'room_id', 'user_id', 'supplier_invoice_no',
    'total_amount', 'discount_amount', 'grand_total', 'status',
    'exchange_rate', 'purchase_date',
])]
class Purchase extends Model
{
    use CreditedByReturns, HidesArchivedPeriod, SoftDeletes;

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

    /** Where the delivery was booked. Null is the shop floor. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(StockRoom::class, 'room_id');
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
     * The currency this invoice was written in — Section 2b, decision 1c.
     *
     * Read off the lines, because that is where it was recorded, and only when
     * the document also carries the rate it was written at. Both or neither: a
     * currency with no rate cannot be printed beside anything.
     */
    public function writtenIn(): ?Currency
    {
        if (! $this->exchange_rate) {
            return null;
        }

        return $this->items
            ->map(fn (PurchaseItem $item) => $item->typedIn())
            ->first(fn (?Currency $currency) => $currency !== null);
    }

    /**
     * A base-currency figure, written in the currency this document names.
     *
     * ⚠️ **Divided by the rate frozen onto the DOCUMENT, never today's.** The
     * currencies table moves every week; a printed invoice must not. Reading
     * the live rate would print $500 in March and $488.89 in April for the same
     * purchase, and the second one would be handed to a supplier as if it were
     * the first. That is the whole of decision 1c.
     */
    public function asWritten(int $base): ?string
    {
        $currency = $this->writtenIn();

        if ($currency === null) {
            return null;
        }

        return number_format($base / $this->exchange_rate, $currency->decimals);
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
        // What has actually been paid on this document: money out settles it,
        // and money the other way is money handed back — change across the
        // counter when the cart is edited down. Netted, or a document could
        // read as paid with the cash already returned.
        return (int) $this->payments()->where('direction', Payment::DIRECTION_OUT)->sum('amount')
            - (int) $this->payments()->where('direction', Payment::DIRECTION_IN)->sum('amount');
    }

    /**
     * What the shop still owes on this purchase.
     *
     * ⚠️ Returns come off it as well as payments — the mirror of the sale side,
     * and it had the same hole. See the trait.
     */
    public function amountDue(): int
    {
        return $this->grand_total - $this->amountPaid() - $this->creditedByReturns();
    }

    protected function returnReferenceType(): string
    {
        return 'purchase_return';
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
            /*
             * ⚠️ **Carried to another room is not the same as used up.**
             *
             * A transfer takes units out of this purchase's layer and opens a
             * new one in the destination room — the source's quantity_remaining
             * drops, so this sum reads a move as a consumption. Soran moves a
             * crate to the back room and the purchase he made ten minutes ago
             * says "2 units have already been used", with nothing sold and the
             * same five units still on the premises.
             *
             * The lock is right and stays: the carried layer points back at a
             * batch this purchase's edit would delete, so editing now would
             * orphan real stock. Only the sentence was wrong, and a wrong
             * sentence here is worse than no sentence — it sends somebody
             * looking for a sale that does not exist.
             *
             * So it says what happened and how to undo it, which is Section 8's
             * own rule: "Delete the dependent record first and the parent
             * unlocks." Deleting the transfer hands these units back to this
             * layer, and the Edit button returns by itself.
             */
            $moved = -(int) StockMovement::whereIn('stock_batch_id', $this->batches()->select('id'))
                ->where('reference_type', StockMovement::REF_TRANSFER)
                ->where('quantity', '<', 0)
                ->sum('quantity');

            $used = $consumed - $moved;

            if ($used > 0) {
                return $deny(trans_choice(
                    '{1}Locked: :count unit from this purchase has already been used.'
                    .'|[2,*]Locked: :count units from this purchase have already been used.',
                    $used, ['count' => $used],
                ));
            }

            return $deny(trans_choice(
                '{1}Locked: :count unit from this purchase has been moved to another room. Delete the transfer first.'
                .'|[2,*]Locked: :count units from this purchase have been moved to another room. Delete the transfer first.',
                $moved, ['count' => $moved],
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
