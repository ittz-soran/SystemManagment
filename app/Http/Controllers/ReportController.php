<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\TradeProfit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Section 9: sales, purchases, profit, discounts received, top products,
 * amounts due and owed.
 *
 * The profit arithmetic is exactly the Section 10b table:
 *
 *   revenue        = sales - sale returns
 *   COGS           = FIFO cost of sale movements - cost reversed by returns
 *   gross profit   = revenue - COGS
 *   net            = gross profit + discounts received - write-offs - expenses
 */
class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->date('from'))->startOfDay()
            : today()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->date('to'))->endOfDay()
            : today()->endOfDay();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'profit' => $this->profit($from, $to),
            'topProducts' => $this->topProducts($from, $to),
            'cash' => $this->cash($from, $to),
            'expensesByCategory' => $this->expensesByCategory($from, $to),
            'byKind' => $this->byKind($from, $to),
            'position' => $this->position(),
        ]);
    }

    /** @return array<string, int> */
    private function profit(Carbon $from, Carbon $to): array
    {
        $sales = (int) Sale::whereBetween('sale_date', [$from, $to])->sum('total_amount');
        $saleReturns = (int) SaleReturn::whereBetween('return_date', [$from, $to])->sum('total_amount');
        $revenue = $sales - $saleReturns;

        // Section 5: profit uses the FIFO cost recorded on each movement, not an
        // average and not the product's purchase_price.
        $cogs = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw('-quantity * unit_cost'));

        $cogsReversed = (int) StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw('quantity * unit_cost'));

        $grossProfit = $revenue - ($cogs - $cogsReversed);

        // Section 6: discounts received = purchase discounts - the shares
        // actually applied on purchase returns.
        $purchaseDiscounts = (int) Purchase::whereBetween('purchase_date', [$from, $to])->sum('discount_amount');

        $sharesApplied = (int) PurchaseReturnItem::whereHas(
            'purchaseReturn',
            fn ($q) => $q->whereBetween('return_date', [$from, $to])
        )->sum('discount_share');

        $discountsReceived = $purchaseDiscounts - $sharesApplied;

        // Section 4: an outgoing adjustment writes stock off at its true FIFO
        // cost, so damage and theft show as a real cost rather than vanishing.
        $writeOffs = (int) StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->where('quantity', '<', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw('-quantity * unit_cost'));

        $expenses = (int) Expense::whereBetween('expense_date', [$from, $to])->sum('amount');

        return [
            'sales' => $sales,
            'sale_returns' => $saleReturns,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'cogs_reversed' => $cogsReversed,
            'gross_profit' => $grossProfit,
            'discounts_received' => $discountsReceived,
            'write_offs' => $writeOffs,
            'expenses' => $expenses,
            'net' => $grossProfit + $discountsReceived - $writeOffs - $expenses,
            'purchases' => (int) Purchase::whereBetween('purchase_date', [$from, $to])->sum('grand_total'),
            'purchase_returns' => (int) PurchaseReturn::whereBetween('return_date', [$from, $to])->sum('total_amount'),
        ];
    }

    /**
     * The three trades, side by side.
     *
     * The shop sells ordinary stock, second-hand things and its own time, and
     * they behave nothing alike: stock turns over on a thin margin, a used
     * machine is a few large bets, and a service is all margin because it costs
     * nothing to give. A single gross-profit figure hides which of the three is
     * carrying the month.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byKind(Carbon $from, Carbon $to): array
    {
        $kinds = [
            Product::KIND_STOCK => __('Products'),
            Product::KIND_USED => __('Second-hand'),
            Product::KIND_SERVICE => __('Services'),
        ];

        $rows = [];

        foreach ($kinds as $kind => $label) {
            $figures = TradeProfit::between(Product::ofKind($kind), $from, $to);

            // A kind the shop does not deal in is not worth a row of zeros.
            if ($figures['units'] === 0 && $figures['revenue'] === 0) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                ...$figures,
                // What is left of every 100 taken. The number a shopkeeper
                // compares between the three without doing the division.
                'margin' => $figures['revenue'] > 0
                    ? (int) round($figures['profit'] / $figures['revenue'] * 100)
                    : 0,
            ];
        }

        return $rows;
    }

    /** Ranked by units actually sold, net of what came back. */
    private function topProducts(Carbon $from, Carbon $to)
    {
        $sold = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('product_id, SUM(quantity - quantity_returned) as units, SUM((quantity - quantity_returned) * unit_price) as revenue')
            ->groupBy('product_id')
            ->havingRaw('SUM(quantity - quantity_returned) > 0')
            ->orderByDesc('units')
            ->limit(10)
            ->get();

        $products = Product::whereIn('id', $sold->pluck('product_id'))->get()->keyBy('id');

        return $sold->map(fn ($row) => [
            'product' => $products[$row->product_id] ?? null,
            'units' => (int) $row->units,
            'revenue' => (int) $row->revenue,
        ])->filter(fn ($row) => $row['product'] !== null);
    }

    /** @return array<string, int> */
    private function cash(Carbon $from, Carbon $to): array
    {
        $in = (int) Payment::whereBetween('paid_at', [$from, $to])
            ->where('direction', Payment::DIRECTION_IN)->sum('amount');

        $out = (int) Payment::whereBetween('paid_at', [$from, $to])
            ->where('direction', Payment::DIRECTION_OUT)->sum('amount');

        return ['in' => $in, 'out' => $out, 'net' => $in - $out];
    }

    private function expensesByCategory(Carbon $from, Carbon $to)
    {
        return Expense::with('category')
            ->whereBetween('expense_date', [$from, $to])
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->groupBy('expense_category_id')
            ->orderByDesc('total')
            ->get();
    }

    /** @return array<string, int> */
    private function position(): array
    {
        return [
            'stock_value' => (int) StockBatch::sum(DB::raw('quantity_remaining * unit_cost')),
            'customers_owe' => (int) Customer::sum('balance'),
            'owed_to_suppliers' => (int) Supplier::sum('balance'),
        ];
    }
}
