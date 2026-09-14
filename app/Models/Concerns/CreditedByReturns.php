<?php

namespace App\Models\Concerns;

use App\Models\AccountTransaction;

/**
 * What returns took off what a document still owes — Section 7.
 *
 * **Soran found this missing on INV-00027, 2026-09-14.** A 180,000 sale with
 * 45,000 returned on one line: the customer's balance was right, and the sale's
 * own Due still read 180,000. He was being shown a debt that no longer existed,
 * on the one screen a shopkeeper opens to find out what somebody owes.
 *
 * The cause is that a return is not a payment. Section 7 settles a refund
 * against the party's BALANCE first, so `total - amountPaid()` never heard
 * about it. Both documents had it and both are fixed here rather than twice.
 *
 * ## ⚠️ The APPLIED figure, never the return's total
 *
 * A refund clears the debt first and hands the rest back in cash. Cash handed
 * back never reduced a debt — there was none left to reduce — so subtracting
 * the whole return would make a fully-paid invoice read as owing money the shop
 * has already passed across the counter.
 *
 * `LedgerService::post` records what it actually applied, precisely so "the
 * ledger and the cached balance can never disagree". This reads that. It also
 * makes a DELETED return right for nothing: its reversal is another row here,
 * and the two net to zero.
 */
trait CreditedByReturns
{
    /** The ledger's word for this document's returns: `sale_return` or `purchase_return`. */
    abstract protected function returnReferenceType(): string;

    /**
     * What returns against this document took off what is owed on it.
     *
     * Negated because the ledger stores a credit as a negative movement — this
     * answers "how much came off", which is a positive number.
     */
    public function creditedByReturns(): int
    {
        /*
         * A subquery rather than a pluck: this is called once per row on a list
         * of twenty-five documents, and two round trips each is fifty queries
         * for a figure that one can answer.
         *
         * `returns()` carries this document's id and the soft-delete scope with
         * it, so a deleted return drops out here — and would net to zero anyway.
         */
        $ofThisDocument = $this->returns()->getQuery()->select(
            $this->returns()->getRelated()->getQualifiedKeyName()
        );

        return -(int) AccountTransaction::query()
            ->where('reference_type', $this->returnReferenceType())
            ->whereIn('reference_id', $ofThisDocument)
            ->sum('amount');
    }
}
