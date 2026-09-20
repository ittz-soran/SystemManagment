<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who owes the shop, and how long they have owed it — Section 9, 2026-09-20.
 *
 * ⚠️ **A BALANCE CANNOT BE AGED.** `customers.balance` is a running total, the
 * latest `balance_after`, and a running total has no dates in it: there is no
 * way to ask it how old the money is. So this is built from documents. Every
 * unsettled sale carries its own date and its own amount due, and that is what
 * lands in a bucket.
 *
 * ⚠️ **And `amountDue()` is reused rather than re-expressed in SQL.** It is
 * `total_amount − amountPaid() − creditedByReturns()`, and the third term is
 * the *applied* credit rather than the return's total — a distinction that has
 * already been a bug once. Writing that again as a raw aggregate would be a
 * second implementation of the subtlest arithmetic in the system, free to drift
 * from the first and wrong in a way nobody would see. The set it runs over is
 * small by its nature: an open document is one somebody is chasing.
 */
class AgedDebtService
{
    /**
     * The buckets, in days, as upper bounds. The last is open-ended.
     *
     * Counted from the document's own date rather than from the last payment
     * against it, because the question the shopkeeper is asking is how long
     * they have been waiting, not how long since something happened.
     */
    public const BUCKETS = [30, 60, 90];

    /** @return list<string> the column headings, in order */
    public static function labels(): array
    {
        return [
            __('Not yet 30 days'),
            __('30–60 days'),
            __('60–90 days'),
            __('Over 90 days'),
        ];
    }

    /** What customers owe the shop. */
    public function receivable(?Carbon $asAt = null): array
    {
        return $this->build(
            Sale::query()->with('payments', 'returns')->whereNotNull('customer_id'),
            'customer_id',
            'sale_date',
            Customer::query(),
            $asAt,
        );
    }

    /** What the shop owes its suppliers. */
    public function payable(?Carbon $asAt = null): array
    {
        return $this->build(
            Purchase::query()->with('payments', 'returns')->whereNotNull('supplier_id'),
            'supplier_id',
            'purchase_date',
            Supplier::query(),
            $asAt,
        );
    }

    /**
     * @param  Builder  $documents
     * @param  Builder  $people
     * @return array{rows: Collection, totals: array<int, int>, outstanding: int, balances: int, unaged: int, as_at: Carbon}
     */
    private function build($documents, string $foreignKey, string $dateColumn, $people, ?Carbon $asAt): array
    {
        $asAt = ($asAt ?? now())->endOfDay();

        $owed = [];

        /*
         * Chunked, because "every document that might still be owed" is not a
         * set this can promise is small — a shop that never records payments
         * has all of them. The relations are eager-loaded per chunk so the
         * amountDue() calls below do not each go back to the database.
         */
        $documents->where($dateColumn, '<=', $asAt)
            ->orderBy('id')
            ->chunk(500, function (Collection $chunk) use (&$owed, $foreignKey, $dateColumn, $asAt) {
                foreach ($chunk as $document) {
                    $due = $document->amountDue();

                    // Settled, or overpaid — neither is a debt.
                    if ($due <= 0) {
                        continue;
                    }

                    $id = $document->{$foreignKey};
                    $days = $document->{$dateColumn}->startOfDay()->diffInDays($asAt->copy()->startOfDay());

                    $owed[$id] ??= array_fill(0, count(self::BUCKETS) + 1, 0);
                    $owed[$id][$this->bucket((int) $days)] += $due;
                }
            });

        $rows = $people->orderBy('name')->get()->map(function ($person) use ($owed) {
            $buckets = $owed[$person->id] ?? array_fill(0, count(self::BUCKETS) + 1, 0);
            $outstanding = array_sum($buckets);

            return (object) [
                'person' => $person,
                'buckets' => $buckets,
                'outstanding' => $outstanding,
                'balance' => (int) $person->balance,
                /*
                 * ⚠️ Shown, not hidden. A balance also carries opening_balance
                 * entries, which belong to no document and so cannot be aged —
                 * so the two legitimately differ and a report showing only
                 * buckets would under-state what is owed. Any OTHER difference
                 * means the documents and the ledger disagree, which is worth
                 * knowing on its own: this doubles as a check on the books.
                 */
                'unaged' => (int) $person->balance - $outstanding,
            ];
        })->filter(
            // Somebody square with the shop has no business on a debt report.
            fn ($row) => $row->outstanding !== 0 || $row->balance !== 0
        )->values();

        $totals = [];

        foreach (array_keys(self::labels()) as $index) {
            $totals[$index] = (int) $rows->sum(fn ($row) => $row->buckets[$index]);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'outstanding' => (int) $rows->sum('outstanding'),
            'balances' => (int) $rows->sum('balance'),
            'unaged' => (int) $rows->sum('unaged'),
            'as_at' => $asAt,
        ];
    }

    /** Which column a debt this old belongs in. */
    private function bucket(int $days): int
    {
        foreach (self::BUCKETS as $index => $limit) {
            if ($days < $limit) {
                return $index;
            }
        }

        return count(self::BUCKETS);
    }
}
