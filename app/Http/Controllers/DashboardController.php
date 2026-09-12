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
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * A total is not an answer — who, and how many, is.
     *
     * **The card said 222,000 and nothing else**, with half of itself empty;
     * Soran drew a question mark in the space. A single figure cannot tell a
     * shopkeeper the one thing that decides what to do about it: whether that
     * is one customer who has not paid, or twenty who each owe a little. The
     * first is a phone call this afternoon and the second is how a shop works.
     *
     * So: the total, how many owe it, and the three largest by name — because
     * "ring Hawkar" is a thing somebody can act on before lunch, and a number
     * is not.
     *
     * Three, not five. This sits beside a figure in half a card, and a list
     * long enough to need scrolling has stopped being a summary.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $accounts
     * @return array{total: int, count: int, top: list<array{name: string, balance: int}>}
     */
    private function whoOwes($accounts): array
    {
        // Owing only, and this is about ZERO rather than about negatives: the
        // suite's own invariant check forbids a negative balance outright, and
        // Section 7 keeps the ledger from writing one. What this keeps out is
        // the settled accounts — a shop with four hundred customers and two
        // debts must read "2 accounts", not "400".
        $owing = (clone $accounts)->where('balance', '>', 0);

        return [
            'total' => (int) (clone $owing)->sum('balance'),
            'count' => (int) (clone $owing)->count(),
            'top' => (clone $owing)
                ->orderByDesc('balance')
                ->limit(3)
                ->get()
                ->map(fn ($account) => [
                    'name' => method_exists($account, 'displayName') ? $account->displayName() : $account->name,
                    'balance' => (int) $account->balance,
                ])
                ->all(),
        ];
    }

    /**
     * A line of costs as the reader is allowed to see them, or nothing.
     *
     * `cost_seen()` returns null for a reader shown no cost at all, and a line
     * drawn from nulls would be a shape made out of the very figures being
     * withheld — Section 2's masking undone by a picture of it. All or none.
     *
     * @param  array<string, int>  $values
     * @return list<int>|null
     */
    private function costsSeen(array $values): ?array
    {
        $seen = array_map(fn (int $value) => cost_seen($value), array_values($values));

        return in_array(null, $seen, true) ? null : array_map('intval', $seen);
    }

    /**
     * The windows the trend chart can be read over.
     *
     * Named rather than a pair of dates in the query string: this is a switch
     * beside a chart, not a filter, and "from 2026-08-16 to 2026-09-12" is a
     * thing to type rather than a thing to press. The lists have real date
     * fields for the other job.
     *
     * @return array<string, array{label: string, from: Carbon}>
     */
    private function windows(): array
    {
        /*
         * Two labels each, and that is not duplication.
         *
         * The button has room for two words; the heading over the chart has to
         * say what is being shown, in words, or it lies the moment the switch
         * moves off its default — a chart headed "The last four weeks" while
         * showing this month is worse than one with no heading at all.
         */
        return [
            'weeks' => [
                'label' => __('4 weeks'),
                'title' => __('The last four weeks'),
                'from' => today()->subDays(27),
            ],
            'month' => [
                'label' => __('This month'),
                'title' => __('This month'),
                'from' => today()->startOfMonth(),
            ],
            'quarter' => [
                'label' => __('3 months'),
                'title' => __('The last three months'),
                'from' => today()->subMonthsNoOverflow(3)->addDay(),
            ],
        ];
    }

    /**
     * @param  string  $window  a key of windows(), or anything at all — an
     *                          unknown one falls back rather than throwing,
     *                          because it arrives from the query string
     */
    private function trend(DailyTotals $totals, $user, string $window = 'weeks'): ?array
    {
        $windows = $this->windows();

        $from = ($windows[$window] ?? $windows['weeks'])['from']->copy()->startOfDay();
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

        // From the query string, so it survives a refresh and can be
        // bookmarked. Validated by windows() rather than here: an unknown one
        // falls back to four weeks instead of throwing, because a person can
        // type anything into a URL and a dashboard is not the place to argue.
        $window = (string) $request->query('trend', 'weeks');

        $sellsCount = $user->hasPermission('sales.view')
            ? Sale::whereDate('sale_date', $today)->count()
            : null;

        /*
         * The shape of the last four weeks, for the line behind each figure.
         *
         * The same window the trend chart uses, and read once for all four
         * tiles: `flows()` is one pass that answers sales and purchases
         * together, and asking it per tile would be four passes for one answer.
         *
         * Each tile's line is behind the same permission as its figure. A line
         * is data too — the shape of somebody's purchasing is worth withholding
         * from a reader who is kept out of the purchases screen, and a chart
         * that leaks what the number withholds is the number not withheld.
         */
        $sparkFrom = today()->subDays(27)->startOfDay();
        $sparkTo = today()->endOfDay();
        $sparkFlows = $totals->flows($sparkFrom, $sparkTo);

        $cards = [
            [
                'label' => __("Today's sales"),
                'value' => $user->hasPermission('sales.view')
                    ? (int) Sale::whereDate('sale_date', $today)->sum('total_amount')
                    : null,
                'icon' => 'cart-check',
                'spark' => $user->hasPermission('sales.view')
                    ? array_values($sparkFlows['sales'])
                    : null,
                'note' => $sellsCount === null
                    ? null
                    : trans_choice('{0}No sales yet|{1}:count sale|[2,*]:count sales', $sellsCount, ['count' => $sellsCount]),
                'cost' => false,

                // The colour this measure wears everywhere on the screen. Tones
                // 1 to 3 are the flows on the trend chart below; 4 was added for
                // expenses, which is the only one of the four that is not on it.
                'tone' => 1,
            ],
            [
                'label' => __("Today's purchases"),
                'value' => $user->hasPermission('purchases.view')
                    ? (int) Purchase::whereDate('purchase_date', $today)->sum('grand_total')
                    : null,
                'icon' => 'bag-check',
                'spark' => $user->hasPermission('purchases.view')
                    ? array_values($sparkFlows['purchases'])
                    : null,
                'note' => null,
                'cost' => false,
                'tone' => 2,
            ],
            [
                'label' => __("Today's expenses"),
                'value' => $user->hasPermission('expenses.view')
                    ? (int) Expense::whereDate('expense_date', $today)->sum('amount')
                    : null,
                'icon' => 'cash-stack',
                'spark' => $user->hasPermission('expenses.view')
                    ? array_values($totals->expenses($sparkFrom, $sparkTo))
                    : null,

                'note' => null,
                'cost' => false,
                'tone' => 4,
            ],
            [
                // Section 4: stock value is the sum of what each remaining unit
                // cost — so it is a cost, and goes through the reader's own
                // cost setting on top of the permission.
                'label' => __('Stock value'),
                'value' => $user->hasPermission('reports.view')
                    ? (int) StockBatch::sum(DB::raw(StockBatch::VALUE))
                    : null,
                'icon' => 'boxes',

                /*
                 * The shelf's worth is a COST, so it goes through the reader's
                 * own cost setting as well as the permission — exactly as the
                 * figure above it does. `cost_seen()` returns null for a reader
                 * shown no cost at all, and a line of nulls would draw a shape
                 * out of the very thing being withheld.
                 */
                'spark' => $user->hasPermission('reports.view')
                    ? $this->costsSeen($totals->stockValue($sparkFrom, $sparkTo))
                    : null,
                'note' => __('At FIFO cost'),
                'cost' => true,

                // No tone, on purpose. The shelf's worth is not a flow, and it
                // wears the level's neutral here for the same reason the band
                // wears it on the chart below — see `.app-spark` in app.scss.
                'tone' => null,

                // And it is not drawn from zero. A shelf worth ninety million
                // every day for a month, drawn from zero, is one solid block
                // with a flat top — see the component.
                'level' => true,
            ],
        ];

        return view('dashboard', [
            'cards' => $cards,

            // The last four weeks, drawn the same way the reports page draws
            // them. Four tiles say what today was; a shopkeeper standing at the
            // counter wants to know whether today was normal, and only a line
            // going back a month can answer that.
            'trend' => $this->trend($totals, $user, $window),
            'trendWindows' => $this->windows(),
            'trendWindow' => $window,

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
                ? $this->whoOwes(Customer::query())
                : null,

            'owedToSuppliers' => $user->hasPermission('suppliers.view')
                ? $this->whoOwes(Supplier::query())
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
