<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Services\DailyTotals;
use App\Services\SetupProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /**
     * Put the first-week checklist away.
     *
     * A shop setting itself up over a fortnight should not be nagged on every
     * page in between, and one that is never going to finish step five should
     * not be reminded of it for ever. Stored as a setting rather than per user,
     * because it is the shop that is set up, not the person looking.
     */
    public function hideSetup(SetupProgress $setup): RedirectResponse
    {
        $setup->hide();

        return back()->with('success', __('Put away. You can still find all of this in the Guide.'));
    }

    /**
     * The last four weeks, for whoever is looking.
     *
     * Every line here is behind the permission of the screen it summarises —
     * the same rule the tiles above it follow. A dashboard that draws a
     * purchases line for somebody kept out of the purchases screen has told
     * them what withholding it was for.
     *
     * Null when there is nothing they may see, so the card goes away rather
     * than appearing as an empty frame.
     *
     * @return array{labels: list<string>, notes: list<string>, series: list<array<string, mixed>>, level: ?array<string, mixed>}|null
     */
    private function trend(DailyTotals $totals, $user): ?array
    {
        $from = today()->subDays(27)->startOfDay();
        $to = today()->endOfDay();

        $axis = $totals->axis($totals->days($from, $to));
        $flows = $totals->flows($from, $to);

        $series = [];

        if ($user->hasPermission('sales.view')) {
            $series[] = ['name' => __('Sales'), 'short' => __('Sales'), 'tone' => 1,
                         'values' => array_values($flows['sales'])];
        }

        if ($user->hasPermission('purchases.view')) {
            $series[] = ['name' => __('Purchases'), 'short' => __('Bought'), 'tone' => 2,
                         'values' => array_values($flows['purchases'])];
        }

        $level = null;

        // Profit and the shelf's worth are both cost figures, and the doc puts
        // the shop's own numbers behind reports.view rather than products.view:
        // the salesperson needs to know what is in stock and what it sells for.
        if ($user->hasPermission('reports.view') && $user->hasPermission('sales.view')) {
            $seen = array_map(fn (int $cost) => cost_seen($cost), $flows['cost']);

            if (! in_array(null, $seen, true)) {
                $series[] = ['name' => __('Profit'), 'short' => __('Profit'), 'tone' => 3,
                             'values' => array_values($totals->profit($flows['sales'], $seen))];

                $level = [
                    'name' => __('Stock value'),
                    'values' => array_map(
                        fn (int $value) => (int) cost_seen($value),
                        array_values($totals->stockValue($from, $to)),
                    ),
                ];
            }
        }

        if ($series === []) {
            return null;
        }

        return [
            'labels' => $axis['labels'],
            'notes' => $axis['notes'],
            'series' => $series,
            'level' => $level,
        ];
    }

    public function index(Request $request, SetupProgress $setup, DailyTotals $totals): View
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

            // The last four weeks, drawn the same way the reports page draws
            // them. Four tiles say what today was; a shopkeeper standing at the
            // counter wants to know whether today was normal, and only a line
            // going back a month can answer that.
            'trend' => $this->trend($totals, $user),

            /*
             * The first-week checklist, for a shop that has not finished
             * setting itself up.
             *
             * Admin only. Three of its five steps need permissions an ordinary
             * user does not have — Settings, suppliers, the catalogue — and a
             * list of instructions somebody cannot follow is worse than no list
             * at all. The person setting a new shop up is its owner.
             */
            'setup' => $user->isAdmin() && $setup->shouldShow() ? $setup : null,

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
