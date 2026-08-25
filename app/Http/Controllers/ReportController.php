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
    /**
     * The period every report is about.
     *
     * One reading of the dates, so a figure on the summary and the same figure
     * on the printed sheet can never come from two different fortnights.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        return [
            $request->filled('from')
                ? Carbon::parse($request->date('from'))->startOfDay()
                : today()->startOfMonth(),
            $request->filled('to')
                ? Carbon::parse($request->date('to'))->endOfDay()
                : today()->endOfDay(),
        ];
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

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

    // ---- Printed reports ------------------------------------------------
    //
    // Set the dates, press the report, and what opens is the paper: the shop's
    // own letterhead, the period across the top, and nothing on it that a
    // printer would waste ink on. The same layout every invoice already uses.

    /** Every sale in the period, and what each one was made of. */
    public function sales(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $sales = Sale::with(['customer', 'items.product', 'returns'])
            ->whereBetween('sale_date', [$from, $to])
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get();

        return view('reports.print.sales', [
            'from' => $from,
            'to' => $to,
            'sales' => $sales,
            'detailed' => $request->boolean('detailed', true),
            // Section 5: the cost of a sale is what its movements recorded, not
            // the product's purchase price and not an average.
            'cost' => $this->costPerDocument(StockMovement::REF_SALE, $sales->pluck('id')),
            // And what came back put its cost back on the shelf. Subtracting the
            // returned money without adding this back charges the sale twice for
            // the same unit, and the figure at the bottom of this sheet stops
            // agreeing with the one on the summary.
            'costReversed' => $this->costReturnedPerSale($sales->pluck('id')),
        ]);
    }

    /** Every purchase in the period, and what each one was made of. */
    public function purchases(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('reports.print.purchases', [
            'from' => $from,
            'to' => $to,
            'purchases' => Purchase::with(['supplier', 'items.product', 'returns'])
                ->whereBetween('purchase_date', [$from, $to])
                ->orderBy('purchase_date')
                ->orderBy('id')
                ->get(),
            'detailed' => $request->boolean('detailed', true),
        ]);
    }

    /** What each customer bought, paid, and still owes. */
    public function customers(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('reports.print.people', [
            'from' => $from,
            'to' => $to,
            'title' => __('Customers'),
            'owedLabel' => __('Owes the shop'),
            'tradeLabel' => __('Sold'),
            'people' => $this->people(Customer::query(), $from, $to, 'customer'),
        ]);
    }

    /** What each supplier sold the shop, was paid, and is still owed. */
    public function suppliers(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('reports.print.people', [
            'from' => $from,
            'to' => $to,
            'title' => __('Suppliers'),
            'owedLabel' => __('The shop owes'),
            'tradeLabel' => __('Bought'),
            'people' => $this->people(Supplier::query(), $from, $to, 'supplier'),
        ]);
    }

    /** The whole period on one sheet. */
    public function summary(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('reports.print.summary', [
            'from' => $from,
            'to' => $to,
            'profit' => $this->profit($from, $to),
            'cash' => $this->cash($from, $to),
            'byKind' => $this->byKind($from, $to),
            'position' => $this->position(),
            'expensesByCategory' => $this->expensesByCategory($from, $to),
            'topProducts' => $this->topProducts($from, $to),
        ]);
    }

    /**
     * The FIFO cost each document consumed, keyed by document.
     *
     * One query for the whole report rather than one per row. Outgoing
     * movements are stored negative, so the sign is turned here.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @return array<int, int>
     */
    private function costPerDocument(string $type, $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return StockMovement::where('reference_type', $type)
            ->whereIn('reference_id', $ids)
            ->groupBy('reference_id')
            ->selectRaw('reference_id, SUM(-quantity * unit_cost) as cost')
            ->pluck('cost', 'reference_id')
            ->map(fn ($cost) => (int) $cost)
            ->all();
    }

    /**
     * The FIFO cost each sale got back when something was returned to it.
     *
     * The movements belong to the return, not to the sale, so the returns are
     * asked which sale they undo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $saleIds
     * @return array<int, int>
     */
    private function costReturnedPerSale($saleIds): array
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
            ->selectRaw('reference_id, SUM(quantity * unit_cost) as cost')
            ->pluck('cost', 'reference_id');

        $bySale = [];

        foreach ($byReturn as $returnId => $cost) {
            $saleId = $returns[$returnId];
            $bySale[$saleId] = ($bySale[$saleId] ?? 0) + (int) $cost;
        }

        return $bySale;
    }

    /**
     * One row per person: what they traded in the period, and where they stand.
     *
     * The traded figures are the period's; the balance is not. What somebody
     * owes is what they owe today — it carries in from before the period and
     * would be a lie if it were cut to fit.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function people($query, Carbon $from, Carbon $to, string $kind)
    {
        $isCustomer = $kind === 'customer';

        $traded = $isCustomer
            ? Sale::whereBetween('sale_date', [$from, $to])
                ->groupBy('customer_id')
                ->selectRaw('customer_id as id, COUNT(*) as documents, SUM(total_amount) as total')
                ->get()->keyBy('id')
            : Purchase::whereBetween('purchase_date', [$from, $to])
                ->groupBy('supplier_id')
                ->selectRaw('supplier_id as id, COUNT(*) as documents, SUM(grand_total) as total')
                ->get()->keyBy('id');

        $returned = $isCustomer
            ? SaleReturn::whereBetween('return_date', [$from, $to])
                ->groupBy('customer_id')
                ->selectRaw('customer_id as id, SUM(total_amount) as total')
                ->get()->keyBy('id')
            : PurchaseReturn::whereBetween('return_date', [$from, $to])
                ->groupBy('supplier_id')
                ->selectRaw('supplier_id as id, SUM(total_amount) as total')
                ->get()->keyBy('id');

        return $query->orderBy('name')->get()->map(fn ($person) => (object) [
            'person' => $person,
            'documents' => (int) ($traded[$person->id]->documents ?? 0),
            'traded' => (int) ($traded[$person->id]->total ?? 0),
            'returned' => (int) ($returned[$person->id]->total ?? 0),
            'balance' => (int) $person->balance,
        ])->filter(
            // Somebody who neither traded in the period nor owes anything has
            // no business on the page.
            fn ($row) => $row->documents > 0 || $row->returned !== 0 || $row->balance !== 0
        )->values();
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
