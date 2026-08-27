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
 * Nothing the reader may not see is queried at all. A figure that is computed
 * and then hidden is one careless template edit away from being shown.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $today = today();
        $threshold = (int) setting('low_stock_threshold', 0);

        $cards = [];

        if ($user->hasPermission('sales.view')) {
            $count = Sale::whereDate('sale_date', $today)->count();

            $cards[] = [
                'label' => __("Today's sales"),
                'value' => (int) Sale::whereDate('sale_date', $today)->sum('total_amount'),
                'icon' => 'cart-check',
                'note' => trans_choice('{0}No sales yet|{1}:count sale|[2,*]:count sales', $count, ['count' => $count]),
            ];
        }

        if ($user->hasPermission('purchases.view')) {
            $cards[] = [
                'label' => __("Today's purchases"),
                'value' => (int) Purchase::whereDate('purchase_date', $today)->sum('grand_total'),
                'icon' => 'bag-check',
                'note' => null,
            ];
        }

        if ($user->hasPermission('expenses.view')) {
            $cards[] = [
                'label' => __("Today's expenses"),
                'value' => (int) Expense::whereDate('expense_date', $today)->sum('amount'),
                'icon' => 'cash-stack',
                'note' => null,
            ];
        }

        if ($user->hasPermission('reports.view')) {
            // Section 4: stock value is the sum of what each remaining unit cost.
            $cards[] = [
                'label' => __('Stock value'),
                'value' => (int) StockBatch::sum(DB::raw('quantity_remaining * unit_cost')),
                'icon' => 'boxes',
                'note' => __('At FIFO cost'),
            ];
        }

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
