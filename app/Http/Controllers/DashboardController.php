<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Section 9: totals, low-stock alerts, today's sales and expenses. */
class DashboardController extends Controller
{
    public function index(): View
    {
        $today = today();
        $threshold = (int) setting('low_stock_threshold', 0);

        return view('dashboard', [
            'todaySalesTotal' => (int) Sale::whereDate('sale_date', $today)->sum('total_amount'),
            'todaySalesCount' => Sale::whereDate('sale_date', $today)->count(),
            'todayPurchasesTotal' => (int) Purchase::whereDate('purchase_date', $today)->sum('grand_total'),
            'todayExpensesTotal' => (int) Expense::whereDate('expense_date', $today)->sum('amount'),

            // Section 4: stock value is the sum of what each remaining unit cost.
            'stockValue' => (int) StockBatch::sum(DB::raw('quantity_remaining * unit_cost')),

            'customersOwe' => (int) Customer::sum('balance'),
            'owedToSuppliers' => (int) Supplier::sum('balance'),

            // Section 8c: a product with no reorder_level falls back to the
            // global low_stock_threshold.
            //
            // Ordinary stock only. A service sits at zero forever and a sold
            // second-hand item is gone for good — neither is running low, and
            // between them they would bury the shelf that actually is.
            'lowStock' => Product::active()
                ->stocked()
                ->with('category')
                ->whereColumn('quantity', '<=', DB::raw("COALESCE(reorder_level, {$threshold})"))
                ->orderBy('quantity')
                ->limit(10)
                ->get(),

            'recentSales' => Sale::with('customer')->orderByDesc('id')->limit(8)->get(),
        ]);
    }
}
