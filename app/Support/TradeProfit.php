<?php

namespace App\Support;

use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a set of products earned between two dates.
 *
 * The one piece of arithmetic the shop is judged by, so it lives in one place:
 * the profit report reads it per kind of product, and the second-hand book reads
 * it for its own items. Written twice, the two would drift, and the day they
 * disagreed neither would be believable.
 *
 * Revenue comes from the sale lines and cost from the movements those lines
 * consumed — never from products.purchase_price, which is a suggestion the
 * product form can change. Both sides of a return come off, so an item sold and
 * given back nets to nothing rather than leaving a profit behind. A service
 * consumes no movements, so its whole price falls through as profit without
 * anything here having to know it is special.
 */
final class TradeProfit
{
    /**
     * @param  Builder  $products  a query selecting the product ids to include
     * @return array{units: int, revenue: int, cost: int, profit: int}
     */
    public static function between(Builder $products, Carbon $from, Carbon $to): array
    {
        // Narrowed to the key here rather than at every call site: a caller
        // passing Product::used() means "these products", not "these rows".
        $ids = fn () => $products->clone()->select('id');

        $lines = SaleItem::query()
            ->whereIn('product_id', $ids())
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]));

        $units = (int) $lines->clone()->sum(DB::raw('quantity - quantity_returned'));
        $revenue = (int) $lines->clone()->sum(DB::raw('(quantity - quantity_returned) * unit_price'));

        $moved = fn (string $reference, string $sign) => (int) StockMovement::query()
            ->whereIn('product_id', $ids())
            ->where('reference_type', $reference)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw($sign.StockMovement::VALUE));

        /*
         * ⚠️ A swap belongs in the cost of what was sold — Soran, 2026-09-24.
         *
         * The invoice is untouched by one, so revenue does not move; but the
         * shop handed over a second unit and got back a faulty one, and the
         * difference is real money. Its two movements say exactly how much:
         * the replacement leaves at what IT cost and the faulty one returns at
         * what IT cost, so their values net to the swap's own `cost()` — the
         * purchase return that follows takes the faulty unit out again at the
         * price the supplier refunds, which nets to nothing for the shop and
         * so is rightly not counted here.
         *
         * Without this line a swap off a dearer layer was profit the shop
         * never made: sold at 60,000 against a 40,000 cost, while a 44,000
         * replacement had walked out of the door.
         */
        $cost = $moved(StockMovement::REF_SALE, '-')
            - $moved(StockMovement::REF_SALE_RETURN, '')
            + $moved(StockMovement::REF_SWAP, '-');

        return [
            'units' => $units,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
        ];
    }
}
