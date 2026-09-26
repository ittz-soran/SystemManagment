<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the profit came from, product by product — Soran, 2026-09-25.
 *
 * *"other report just show fully where profit are come in to shop, not problem
 * if need more A4 pages"*.
 *
 * ⚠️ **IT MUST ADD UP TO `TradeProfit`, TO THE DINAR.** A breakdown that does
 * not sum to the headline is worse than no breakdown: it gives a shopkeeper
 * two figures for the same month and no way to tell which one to believe. So
 * every row here is cut from the same two sources `TradeProfit` uses — revenue
 * off the invoice lines, cost off the movements those lines consumed — and
 * `ProfitBreakdownTest` holds the sum of the rows against the total.
 *
 * ⚠️ **One query per figure, not one per product.** A shop with two thousand
 * products asking `TradeProfit` two thousand times would time out before it
 * printed anything, and the report is meant to be read over a month's trade.
 */
final class ProfitBreakdown
{
    /**
     * Every product that sold in the period, richest first.
     *
     * @return Collection<int, object{product_id: int, name: string, sku: string,
     *     kind: string, category: ?string, units: int, revenue: int, cost: int,
     *     profit: int, margin: int}>
     */
    public function byProduct(Carbon $from, Carbon $to): Collection
    {
        /*
         * ⚠️ **A return belongs to the day IT was written, not to the day the
         * sale was** — Soran, 2026-09-26. This read `quantity - quantity_returned`
         * off the sale line, and that column is the current state with no date
         * on it: a sale on the 24th returned on the 25th had its money taken
         * away on the 24th, while `costPerProduct()` below — which has always
         * filtered by `occurred_at` — left the cost there. Half the subtraction
         * landed. The period's own total stayed right, so it only showed when a
         * single day was read on its own.
         */
        $sold = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('SUM(quantity) as units')
            ->selectRaw('SUM(quantity * unit_price) as revenue')
            ->get()
            ->keyBy('product_id');

        $back = SaleReturnItem::query()
            ->whereHas('saleReturn', fn ($q) => $q->whereBetween('return_date', [$from, $to]))
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('SUM(quantity) as units')
            ->selectRaw('SUM(quantity * unit_price) as revenue')
            ->get()
            ->keyBy('product_id');

        $cost = $this->costPerProduct($from, $to);

        /*
         * ⚠️ Keyed off BOTH, because a product can appear in a period through a
         * refund alone — sold last week, brought back today. Left out, the
         * product rows no longer add up to the headline that already counts it.
         */
        $ids = $sold->keys()->merge($back->keys())->merge($cost->keys())->unique();

        $products = Product::with('category')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids
            ->map(function ($id) use ($products, $cost, $sold, $back) {
                $product = $products[$id] ?? null;

                if ($product === null) {
                    return null;
                }

                $earned = (int) ($sold[$id]->revenue ?? 0) - (int) ($back[$id]->revenue ?? 0);
                $spent = (int) ($cost[$id] ?? 0);
                $units = (int) ($sold[$id]->units ?? 0) - (int) ($back[$id]->units ?? 0);

                return (object) [
                    'product_id' => (int) $id,
                    'name' => $product->name,
                    'sku' => (string) $product->sku,
                    'kind' => $product->kind,
                    'category' => $product->category?->name,
                    'units' => $units,
                    'revenue' => $earned,
                    'cost' => $spent,
                    'profit' => $earned - $spent,
                    'margin' => $earned > 0 ? (int) round(($earned - $spent) / $earned * 100) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * The same rows gathered into their categories.
     *
     * @param  Collection<int, object>  $byProduct
     * @return Collection<int, object>
     */
    public function byCategory(Collection $byProduct): Collection
    {
        return $byProduct
            ->groupBy(fn (object $row) => $row->category ?? __('No category'))
            ->map(function (Collection $rows, string $name) {
                $revenue = (int) $rows->sum('revenue');
                $cost = (int) $rows->sum('cost');

                return (object) [
                    'category' => $name,
                    'products' => $rows->count(),
                    'units' => (int) $rows->sum('units'),
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? (int) round(($revenue - $cost) / $revenue * 100) : 0,
                ];
            })
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * The FIFO cost each product's sales consumed, less what came back.
     *
     * ⚠️ **Both sides, or a returned unit leaves a profit behind it.** The sale
     * charged its cost out; the return put that same cost back on the shelf.
     * Subtracting the refunded money without adding the cost back charges the
     * month twice for one unit — the exact mistake the printed sales report
     * already carries a comment about.
     *
     * @return Collection<int, int>
     */
    private function costPerProduct(Carbon $from, Carbon $to): Collection
    {
        $sum = fn (string $reference, string $sign) => StockMovement::query()
            ->where('reference_type', $reference)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('SUM('.$sign.'('.StockMovement::VALUE.')) as value')
            ->pluck('value', 'product_id');

        $out = $sum(StockMovement::REF_SALE, '-');
        $back = $sum(StockMovement::REF_SALE_RETURN, '');

        /*
         * ⚠️ And the swap, on the product that carried it — the same term the
         * shop-wide figure adds. The invoice was untouched, so no revenue
         * moved; the shop still handed over a second unit, and it belongs
         * against the product that cost it.
         */
        $swaps = $sum(StockMovement::REF_SWAP, '-');

        return collect($out->keys())
            ->merge($back->keys())
            ->merge($swaps->keys())
            ->unique()
            ->mapWithKeys(fn ($id) => [
                (int) $id => (int) ($out[$id] ?? 0) - (int) ($back[$id] ?? 0) + (int) ($swaps[$id] ?? 0),
            ]);
    }

    /**
     * Every invoice line in the period, with what it earned and what it cost.
     *
     * ⚠️ Cost is read per LINE, off `reference_item_id` — the column that
     * exists so a line filled from two batches can still say what those two
     * batches charged it. Reading it per document and dividing would invent a
     * figure for every line of a mixed invoice.
     *
     * @return Collection<int, object>
     */
    public function lines(Carbon $from, Carbon $to): Collection
    {
        $out = StockMovement::query()
            ->where('reference_type', StockMovement::REF_SALE)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('reference_item_id')
            ->groupBy('reference_item_id')
            ->selectRaw('reference_item_id')
            ->selectRaw('SUM(-('.StockMovement::VALUE.')) as value')
            ->pluck('value', 'reference_item_id');

        /*
         * ⚠️ **A return movement names the RETURN's line, not the sale's.**
         * `restoreForSaleItem` writes `reference_item_id = saleReturnItemId`,
         * so the cost a return handed back cannot be keyed to an invoice line
         * without going through `sale_return_items` — and a first version that
         * skipped that step left the returned COST on the line while the
         * returned money had already come off it, making every returned line
         * look like a loss.
         */
        $back = DB::table('stock_movements')
            ->join('sale_return_items', 'sale_return_items.id', '=', 'stock_movements.reference_item_id')
            ->where('stock_movements.reference_type', StockMovement::REF_SALE_RETURN)
            ->whereBetween('stock_movements.occurred_at', [$from, $to])
            ->groupBy('sale_return_items.sale_item_id')
            ->selectRaw('sale_return_items.sale_item_id as sale_item_id')
            ->selectRaw('SUM(stock_movements.quantity * stock_movements.unit_cost) as value')
            ->pluck('value', 'sale_item_id');

        $cost = collect($out->keys())->merge($back->keys())->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => (int) ($out[$id] ?? 0) - (int) ($back[$id] ?? 0)]);

        /*
         * ⚠️ **What came back INSIDE the window, keyed to the line it came off**
         * — Soran, 2026-09-26. The old version took `quantity_returned` off the
         * sale line, which is the current state and carries no date, so a line
         * sold on the 24th and returned on the 25th read as returned on the
         * 24th too — while the cost above, filtered by `occurred_at`, did not.
         */
        $refunded = SaleReturnItem::query()
            ->whereHas('saleReturn', fn ($q) => $q->whereBetween('return_date', [$from, $to]))
            ->groupBy('sale_item_id')
            ->selectRaw('sale_item_id')
            ->selectRaw('SUM(quantity) as units')
            ->selectRaw('SUM(quantity * unit_price) as revenue')
            ->get()
            ->keyBy('sale_item_id');

        $sold = SaleItem::with('sale.customer', 'product')
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->get();

        /*
         * ⚠️ **And the refunds whose SALE is not in this window at all.** Sold
         * last week, brought back today: the money and the cost are this
         * period's, and there is no line here to hang them on. Left out, this
         * section stops adding up to the headline above it — which is the one
         * thing the sheet promises. They come in as their own rows, and the
         * view marks them so a reader is not left wondering why an invoice from
         * before the period is on the page.
         */
        $orphans = SaleItem::with('sale.customer', 'product')
            ->whereIn('id', $refunded->keys())
            ->whereDoesntHave('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->get();

        return $sold->merge($orphans)
            ->map(function (SaleItem $line) use ($cost, $refunded, $from, $to) {
                $inWindow = $line->sale->sale_date->betweenIncluded($from, $to);

                $units = ($inWindow ? (int) $line->quantity : 0)
                    - (int) ($refunded[$line->id]->units ?? 0);

                $revenue = ($inWindow ? (int) $line->quantity * (int) $line->unit_price : 0)
                    - (int) ($refunded[$line->id]->revenue ?? 0);

                $spent = (int) ($cost[$line->id] ?? 0);

                return (object) [
                    'sale' => $line->sale,
                    'product' => $line->product,
                    'units' => $units,
                    'unit_price' => (int) $line->unit_price,
                    'revenue' => $revenue,
                    'cost' => $spent,
                    'profit' => $revenue - $spent,

                    // The view says so on the row rather than leaving an
                    // invoice from before the period looking like a mistake.
                    'refund_only' => ! $inWindow,
                ];
            })
            ->filter(fn (object $row) => $row->units !== 0 || $row->revenue !== 0 || $row->cost !== 0)
            ->sortBy([
                fn (object $a, object $b) => $a->sale->sale_date <=> $b->sale->sale_date,
                fn (object $a, object $b) => $a->sale->id <=> $b->sale->id,
            ])
            ->values();
    }
}
