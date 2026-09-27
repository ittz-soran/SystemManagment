<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * What a SET OF INVOICES turned out to be worth — Soran, 2026-09-27.
 *
 * ⚠️ **This is the cohort clock, and `TradeProfit` is the period clock.** They
 * answer different questions and both are right:
 *
 * - Here: *how did the sales written in this period turn out* — everything
 *   returned against them comes off, whenever it was returned.
 * - `TradeProfit`: *what happened between these dates* — a sale on the 24th is
 *   the 24th's, its return on the 25th is the 25th's.
 *
 * Over a window holding both documents they agree exactly. On a single day they
 * can differ, and on 2026-09-24 they did: 14,300 here against 24,800 there.
 * Anything printing a figure from this class must say which question it
 * answered, or it is the unlabelled scope that cost this shop two days.
 *
 * ⚠️ **Extracted from `ReportController`, not written afresh.** The sales
 * report has computed exactly this since it was built; a second implementation
 * is how the shop came to have two clocks in the first place. One calculation,
 * every screen that needs it.
 */
final class DocumentProfit
{
    /**
     * The FIFO cost each document's own movements recorded.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, int>
     */
    public static function costPerDocument(string $referenceType, Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return StockMovement::where('reference_type', $referenceType)
            ->whereIn('reference_id', $ids)
            ->groupBy('reference_id')
            ->selectRaw('reference_id, SUM(-'.StockMovement::VALUE.') as cost')
            ->pluck('cost', 'reference_id')
            ->map(fn ($cost) => (int) $cost)
            ->all();
    }

    /**
     * The FIFO cost each sale got back when something was returned to it.
     *
     * ⚠️ **The movements belong to the RETURN, not to the sale**, so they have
     * to be walked back through `sale_returns` to land on the invoice that sold
     * them. Subtracting the refunded money without adding this cost back
     * charges the month twice for one unit.
     *
     * @param  Collection<int, int>  $saleIds
     * @return array<int, int>
     */
    public static function costReturnedPerSale(Collection $saleIds): array
    {
        if ($saleIds->isEmpty()) {
            return [];
        }

        $returns = SaleReturn::whereIn('sale_id', $saleIds)->pluck('sale_id', 'id');

        if ($returns->isEmpty()) {
            return [];
        }

        $byReturn = StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->whereIn('reference_id', $returns->keys())
            ->groupBy('reference_id')
            ->selectRaw('reference_id, SUM('.StockMovement::VALUE.') as cost')
            ->pluck('cost', 'reference_id');

        $bySale = [];

        foreach ($byReturn as $returnId => $cost) {
            $saleId = $returns[$returnId];
            $bySale[$saleId] = ($bySale[$saleId] ?? 0) + (int) $cost;
        }

        return $bySale;
    }

    /**
     * What these invoices cost the shop, in one figure.
     *
     * @param  Collection<int, int>  $saleIds
     */
    public static function costOfSales(Collection $saleIds): int
    {
        $ids = $saleIds->values();

        return array_sum(self::costPerDocument(StockMovement::REF_SALE, $ids))
            - array_sum(self::costReturnedPerSale($ids));
    }

    /**
     * What each of these invoices earned, keyed by sale id.
     *
     * ⚠️ **Four queries for the whole page, never four per row.** A list of
     * twenty-five invoices asking this one at a time is a hundred round trips
     * for a column — the same reason `ProfitBreakdown` gathers its figures in
     * one query per figure rather than calling `TradeProfit` per product.
     *
     * ⚠️ Revenue is the invoice less **the returns' own totals**, not less what
     * a return credited to a balance. A refund paid in cash credits no balance
     * at all, so reading the credit leaves the returned money in the profit —
     * which is exactly what the first version of the list's own total did, and
     * it read 43,300 on a shop that had made 20,300.
     *
     * @param  Collection<int, int>  $saleIds
     * @return array<int, array{revenue: int, cost: int, profit: int}>
     */
    public static function perSale(Collection $saleIds): array
    {
        $ids = $saleIds->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $sold = Sale::whereIn('id', $ids)->pluck('total_amount', 'id');

        $returned = SaleReturn::whereIn('sale_id', $ids)
            ->groupBy('sale_id')
            ->selectRaw('sale_id, SUM(total_amount) as total')
            ->pluck('total', 'sale_id');

        $cost = self::costPerDocument(StockMovement::REF_SALE, $ids);
        $costBack = self::costReturnedPerSale($ids);

        $out = [];

        foreach ($ids as $id) {
            $revenue = (int) ($sold[$id] ?? 0) - (int) ($returned[$id] ?? 0);
            $spent = (int) ($cost[$id] ?? 0) - (int) ($costBack[$id] ?? 0);

            $out[(int) $id] = [
                'revenue' => $revenue,
                'cost' => $spent,
                'profit' => $revenue - $spent,
            ];
        }

        return $out;
    }
}
