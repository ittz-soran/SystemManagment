<?php

namespace App\Services;

use App\Models\DocumentCounter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 7b: every document has a human-readable number, PREFIX-NNNNN.
 *
 * The counter is incremented inside the caller's transaction with
 * SELECT ... FOR UPDATE, so two users saving at once cannot get the same number.
 * That also means a rolled-back document consumes no number — which is what
 * acceptance test T8 asserts when an oversell is rejected.
 */
class DocumentNumberService
{
    public const PREFIX_SALE = 'INV';

    public const PREFIX_PURCHASE = 'PUR';

    public const PREFIX_PAYMENT = 'PAY';

    public const PREFIX_SALE_RETURN = 'SRT';

    public const PREFIX_PURCHASE_RETURN = 'PRT';

    public const PREFIX_EXPENSE = 'EXP';

    public const PREFIX_ADJUSTMENT = 'ADJ';

    /** Section 4: the SKU counter, whose prefix is configurable via settings. */
    public const PREFIX_SKU = 'SKU';

    public const ALL_PREFIXES = [
        self::PREFIX_SALE,
        self::PREFIX_PURCHASE,
        self::PREFIX_PAYMENT,
        self::PREFIX_SALE_RETURN,
        self::PREFIX_PURCHASE_RETURN,
        self::PREFIX_EXPENSE,
        self::PREFIX_ADJUSTMENT,
        self::PREFIX_SKU,
    ];

    /**
     * Take the next number for a prefix and format it as PREFIX-NNNNN.
     *
     * Zero-padded to 5 digits; lets it overflow naturally past 99,999.
     * Numbers are never reused, even after deletion — a gap in the sequence is
     * normal and is itself useful information.
     */
    public function next(string $prefix): string
    {
        return $prefix.'-'.str_pad((string) $this->nextNumber($prefix), 5, '0', STR_PAD_LEFT);
    }

    /**
     * The raw next number, without the prefix. Used by SKU generation, which
     * formats differently (`SS65`, not `SS-00065`).
     */
    public function nextNumber(string $prefix): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                "Document numbers must be taken inside a transaction so a rolled-back ".
                "document consumes no number. Called for prefix [{$prefix}] with no ".
                "active transaction."
            );
        }

        // SELECT ... FOR UPDATE on the counter row. Two users saving at the same
        // moment queue here rather than both reading the same value.
        $counter = DocumentCounter::where('prefix', $prefix)->lockForUpdate()->first();

        if (! $counter) {
            // First ever document for this prefix. A unique index on `prefix`
            // means a concurrent creator loses the race and we re-read below.
            try {
                $counter = DocumentCounter::create(['prefix' => $prefix, 'next_number' => 1]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $counter = DocumentCounter::where('prefix', $prefix)->lockForUpdate()->firstOrFail();
            }
        }

        $number = $counter->next_number;

        $counter->forceFill(['next_number' => $number + 1])->save();

        return $number;
    }
}
