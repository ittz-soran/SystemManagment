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
            ->sum(DB::raw($sign.'quantity * unit_cost'));

        $cost = $moved(StockMovement::REF_SALE, '-') - $moved(StockMovement::REF_SALE_RETURN, '');

        return [
            'units' => $units,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
        ];
    }
}
