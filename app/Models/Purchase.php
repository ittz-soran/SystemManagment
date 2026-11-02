<?php

namespace App\Models;

use App\Models\Concerns\CreditedByReturns;
use App\Models\Concerns\HidesArchivedPeriod;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Fillable([
    'document_no', 'supplier_id', 'room_id', 'user_id', 'supplier_invoice_no',
    'total_amount', 'discount_amount', 'grand_total', 'status',
    'exchange_rate', 'foreign_currency', 'foreign_total', 'purchase_date',
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
            'foreign_total' => 'integer',
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
     * What has been paid to the supplier so far, in the dinars it settled.
     *
     * Section 4 gives the amount-due formula as
     * `grand_total - SUM(amount WHERE direction = 'in')`, but that sentence
     * describes the sale side. The same section also says `direction = out` is
     * "money leaving the till — a cash refund to a customer, or paying a
     * supplier", so on a purchase the settling payments are the outbound ones.
     * Reading `in` here would report every supplier payment as cash arriving.
     *
     * ⚠️ **Settled, not spent** — Section 6, *A dollar purchase is owed in
     * dollars*. $16 paid at 1,500 is 24,000 dinars out of the till, and settles
     * the 23,360 the purchase was written for at 1,460; the 640 between them is
     * the payment's `rate_difference`, which the profit report carries. Every
     * other payment has no difference, and this is the sum it always was.
     *
     * `$except` leaves one payment out — the one being edited.
     */
    public function amountPaid(?Payment $except = null): int
    {
        // What has actually been paid on this document: money out settles it,
        // and money the other way is money handed back — change across the
        // counter when the cart is edited down. Netted, or a document could
        // read as paid with the cash already returned.
        return $this->paidIn(Payment::SETTLED, $except);
    }

    /** The dinars that actually left the till for this purchase. */
    public function cashPaid(): int
    {
        return $this->paidIn('amount');
    }

    /** What the rate cost on this purchase's payments: above zero a loss. */
    public function rateDifference(): int
    {
        return $this->paidIn('rate_difference');
    }

    /** Money out less money back, summed over one expression of a payment. */
    private function paidIn(string $expression, ?Payment $except = null): int
    {
        $sum = fn (string $direction) => (int) $this->payments()
            ->where('direction', $direction)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum(DB::raw($expression));

        return $sum(Payment::DIRECTION_OUT) - $sum(Payment::DIRECTION_IN);
    }

    /**
     * What the shop still owes on this purchase, in dinars at the purchase's
     * own rate.
     *
     * ⚠️ Returns come off it as well as payments — the mirror of the sale side,
     * and it had the same hole. See the trait.
     */
    public function amountDue(?Payment $except = null): int
    {
        return $this->grand_total - $this->amountPaid($except) - $this->creditedByReturns();
    }

    /**
     * The currency this purchase is OWED in — Section 6, Soran, 2026-11-02:
     * *"i have inv all in usd"*. Null for a purchase in the shop's own money.
     */
    public function owedIn(): ?Currency
    {
        if ($this->foreign_total === null || ! $this->foreign_currency) {
            return null;
        }

        return Currency::cached()[$this->foreign_currency] ?? null;
    }

    /**
     * A count of the purchase's own currency's minor units, written as a
     * figure with its mark — *16 $*. ⚠️ Not `money($x, in: $currency)`: that
     * converts FROM dinars, and these are cents already.
     */
    public function inItsCurrency(int $minor): string
    {
        $currency = $this->owedIn();

        return $currency === null
            ? money($minor)
            : Money::writeAt($minor, $currency->decimals).' '.$currency->mark();
    }

    /** Dollars paid, in cents: money out less money back. */
    public function foreignPaid(?Payment $except = null): int
    {
        return $this->paidIn('COALESCE(foreign_amount, 0)', $except);
    }

    /**
     * The dollars a return took off, in proportion to the dinars it took off —
     * so a return of everything takes off every dollar.
     */
    public function foreignCredited(): int
    {
        if ((int) $this->grand_total <= 0 || $this->foreign_total === null) {
            return 0;
        }

        return (int) round($this->creditedByReturns() * $this->foreign_total / $this->grand_total);
    }

    /**
     * What is still owed in the purchase's own currency, in its minor units;
     * null for a purchase in dinars. Below zero is paid too much.
     *
     * Settled in dinars means settled in dollars too: the closing payment
     * settles the last dinars, so a cent left over by a rounding of a return is
     * not a debt.
     */
    public function foreignDue(?Payment $except = null): ?int
    {
        if ($this->owedIn() === null) {
            return null;
        }

        if ($this->amountDue($except) === 0) {
            return 0;
        }

        return $this->foreign_total - $this->foreignPaid($except) - $this->foreignCredited();
    }

    /**
     * What the dollars still owed would cost in dinars at TODAY's rate — an
     * estimate, shown beside them and never saved. Null when nothing is owed.
     */
    public function dueAtTodaysRate(): ?int
    {
        $currency = $this->owedIn();
        $due = $this->foreignDue();

        if ($currency === null || $due === null || $due <= 0) {
            return null;
        }

        return Money::toBase($due, $currency);
    }

    /**
     * How a payment of `$foreign` (minor units) at `$rate` settles this
     * purchase — Section 6. The dinars that leave, the dinars of the purchase
     * they settle, and the difference between them.
     *
     * ⚠️ **The closing payment** — the one whose dollars reach what is still
     * owed — settles every dinar still owed, so the purchase and the
     * supplier's account close at exactly zero; its difference carries the
     * rounding of the lines along with the rate. A payment for less settles
     * dollars × the purchase's rate, and never so much that the dinars run out
     * while dollars are still owed.
     *
     * @return array{amount: int, settled: int, difference: int}
     *
     * @throws RuntimeException more than is owed
     */
    public function settle(int $foreign, int $rate, ?Payment $except = null): array
    {
        $currency = $this->owedIn();

        if ($currency === null) {
            throw new RuntimeException(__('This purchase is not owed in another currency.'));
        }

        $due = (int) $this->foreignDue($except);

        if ($foreign > $due) {
            throw new RuntimeException(__(':left is left on :document.', [
                'left' => $this->inItsCurrency(max(0, $due)),
                'document' => $this->document_no,
            ]));
        }

        $per = $currency->minorPerMajor();
        $amount = (int) round($foreign * $rate / $per);
        $owed = $this->amountDue($except);

        $settled = $foreign === $due
            ? max(0, $owed)
            : max(0, min((int) round($foreign * $this->exchange_rate / $per), $owed - 1));

        return ['amount' => $amount, 'settled' => $settled, 'difference' => $amount - $settled];
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
