<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Section 9: totals, low-stock alerts, today's sales and expenses.
 *
 * Every figure is behind the permission of the screen it summarises, the same
 * rule the search box follows: a tile that shows what the shop spent today has
 * told the reader what withholding the expenses screen was for. `dashboard.view`
 * opens the page; it does not open what is on it.
 *
 * Cost is the line that matters most here. What the shelf cost — and therefore
 * what the shop makes on a sale — is a `reports.view` figure, not a
 * `products.view` one: the salesperson needs to know what is in stock and what
 * it sells for, and the doc's own permission vocabulary has a key for the
 * shop's numbers.
 *
 * A figure they may not see is *****, not a missing tile. Taking the tile away
 * says the shop has no such number; masking it says there is one and it is not
 * theirs, which is both true and what was asked for. Either way it is never
 * queried — a figure that is computed and then hidden is one careless template
 * edit away from being shown.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $today = today();
        $threshold = (int) setting('low_stock_threshold', 0);

        $sellsCount = $user->hasPermission('sales.view')
            ? Sale::whereDate('sale_date', $today)->count()
            : null;

        $cards = [
            [
                'label' => __("Today's sales"),
                'value' => $user->hasPermission('sales.view')
                    ? (int) Sale::whereDate('sale_date', $today)->sum('total_amount')
                    : null,
                'icon' => 'cart-check',
                'note' => $sellsCount === null
                    ? null
                    : trans_choice('{0}No sales yet|{1}:count sale|[2,*]:count sales', $sellsCount, ['count' => $sellsCount]),
                'cost' => false,
            ],
            [
                'label' => __("Today's purchases"),
                'value' => $user->hasPermission('purchases.view')
                    ? (int) Purchase::whereDate('purchase_date', $today)->sum('grand_total')
                    : null,
                'icon' => 'bag-check',
                'note' => null,
                'cost' => false,
            ],
            [
                'label' => __("Today's expenses"),
                'value' => $user->hasPermission('expenses.view')
                    ? (int) Expense::whereDate('expense_date', $today)->sum('amount')
                    : null,
                'icon' => 'cash-stack',
                'note' => null,
                'cost' => false,
            ],
            [
                // Section 4: stock value is the sum of what each remaining unit
                // cost — so it is a cost, and goes through the reader's own
                // cost setting on top of the permission.
                'label' => __('Stock value'),
                'value' => $user->hasPermission('reports.view')
                    ? (int) StockBatch::sum(DB::raw('quantity_remaining * unit_cost'))
                    : null,
                'icon' => 'boxes',
                'note' => __('At FIFO cost'),
                'cost' => true,
            ],
        ];

        return view('dashboard', [
            'cards' => $cards,

            'customersOwe' => $user->hasPermission('customers.view')
                ? (int) Customer::sum('balance')
                : null,

            'owedToSuppliers' => $user->hasPermission('suppliers.view')
                ? (int) Supplier::sum('balance')
                : null,

            // Section 8c: a product with no reorder_level falls back to the
            // global low_stock_threshold.
            //
            // Ordinary stock only. A service sits at zero forever and a sold
            // second-hand item is gone for good — neither is running low, and
            // between them they would bury the shelf that actually is.
            'lowStock' => $user->hasPermission('products.view')
                ? Product::active()
                    ->stocked()
                    ->with('category')
                    ->whereColumn('quantity', '<=', DB::raw("COALESCE(reorder_level, {$threshold})"))
                    ->orderBy('quantity')
                    ->limit(10)
                    ->get()
                : null,

            'recentSales' => $user->hasPermission('sales.view')
                ? Sale::with('customer')->orderByDesc('id')->limit(8)->get()
                : null,
        ]);
    }
}
