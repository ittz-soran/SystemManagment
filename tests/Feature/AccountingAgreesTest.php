<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SwapService;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every figure in the shop, cross-checked against every other — Soran,
 * 2026-09-25.
 *
 * *"i detect some wrong Accounting, please full check because this main thing
 * that i want use system to i check monthly or weakly ... i have afraid for
 * all another Accounting that i depend it"*.
 *
 * ⚠️ **The figures were right; the pages did not say what they were counting.**
 * The Services page totalled ALL TIME, the profit report totalled THIS MONTH,
 * and neither wrote the period anywhere a reader could see it. Two true
 * answers to two different questions, printed as though they were answers to
 * the same one — which is indistinguishable from a broken system when the
 * money is yours.
 *
 * So this file exists to make the accusation checkable rather than arguable.
 * It builds a shop with every awkward shape in it — sales across two months,
 * a return, a swap, a service with no cost, stock on more than one layer — and
 * then asks the same question through every route the shop offers. Where two
 * routes answer the same question they must give the same number, to the
 * dinar; where they answer different questions, the difference must be the
 * period and nothing else.
 *
 * ⚠️ A failure here is never cosmetic. It means two screens are telling a
 * shopkeeper different things about the same money.
 */
class AccountingAgreesTest extends TestCase
{
    use RefreshDatabase;

    private Product $charger;

    private Product $service;

    private Customer $karwan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->buildAShopWithAHistory();
    }

    /** Far enough back to mean "all of it" — the Find page's own window. */
    private function beginning(): Carbon
    {
        return Carbon::create(1970, 1, 1)->startOfDay();
    }

    private function ever(): array
    {
        return [$this->beginning(), now()->addDay()];
    }

    private function thisMonth(): array
    {
        return [today()->startOfMonth(), today()->endOfDay()];
    }

    /**
     * A shop with a past: two months of trade, both kinds of product, a return
     * and a swap. Every figure below is read off THIS.
     */
    private function buildAShopWithAHistory(): void
    {
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::first();
        $supplier = Supplier::create(['name' => 'Rasan', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $this->charger = Product::create([
            'name' => 'Charger 33W', 'kind' => Product::KIND_STOCK, 'sku' => 'CHG-33W',
            'barcode' => 'CHG33W', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 11_000, 'sale_price' => 18_000, 'quantity' => 0,
        ]);

        $this->service = Product::create([
            'name' => 'Game Pass', 'kind' => Product::KIND_SERVICE, 'sku' => 'SS23',
            'barcode' => null, 'category_id' => $category->id, 'unit' => 'each',
            'purchase_price' => 0, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        /*
         * Two layers, and the cheap one sized to run out EXACTLY on the unit
         * that gets swapped — 4 + 6 + 2 + 1 is thirteen. Without that the
         * replacement comes off the same layer as the faulty one, the swap
         * costs nothing, and the test that says a swap moves cost proves
         * nothing at all.
         */
        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->charger->id, 'quantity' => 13, 'unit_price' => 11_000]],
            user: $user, purchaseDate: now()->subMonth()->startOfMonth(), amountPaid: 143_000,
        );
        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->charger->id, 'quantity' => 20, 'unit_price' => 12_000]],
            user: $user, purchaseDate: today()->startOfMonth(), amountPaid: 240_000,
        );

        $sell = fn (array $lines, Carbon $on, int $paid) => app(SaleService::class)->create(
            customer: $this->karwan, lines: $lines, user: $user,
            saleDate: $on, amountPaid: $paid, paymentMethod: 'cash',
        );

        // LAST month — outside the report's default window, inside all time.
        $sell([['product_id' => $this->charger->id, 'quantity' => 4, 'unit_price' => 18_000]],
            now()->subMonth()->startOfMonth()->addDays(3), 72_000);
        $sell([['product_id' => $this->service->id, 'quantity' => 8, 'unit_price' => 5_000]],
            now()->subMonth()->startOfMonth()->addDays(5), 40_000);

        // THIS month, and one of them left partly on account.
        $sell([['product_id' => $this->charger->id, 'quantity' => 6, 'unit_price' => 18_000]],
            today()->startOfMonth()->addDays(2), 50_000);
        $sell([['product_id' => $this->service->id, 'quantity' => 3, 'unit_price' => 5_000]],
            today()->startOfMonth()->addDays(4), 15_000);

        // A return and a swap, both this month. The swap goes first, while the
        // cheap layer is exactly empty.
        $returned = $sell([['product_id' => $this->charger->id, 'quantity' => 2, 'unit_price' => 18_000]],
            today()->startOfMonth()->addDays(6), 36_000);

        $swapped = $sell([['product_id' => $this->charger->id, 'quantity' => 1, 'unit_price' => 18_000]],
            today()->startOfMonth()->addDays(7), 18_000);

        app(SwapService::class)->create(
            saleItem: $swapped->items()->firstOrFail(), quantity: 1, user: $user,
        );

        app(SaleReturnService::class)->create(
            sale: $returned,
            lines: [['sale_item_id' => $returned->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $user, returnDate: today(), paymentMethod: 'cash',
        );
    }

    // ---- The books against themselves --------------------------------------

    /** ⚠️ A cached balance that has drifted from its ledger is money invented. */
    public function test_every_cached_balance_equals_the_ledger_that_made_it(): void
    {
        foreach (Customer::all() as $customer) {
            $this->assertSame(
                (int) AccountTransaction::where('accountable_type', 'customer')
                    ->where('accountable_id', $customer->id)->sum('amount'),
                (int) $customer->balance,
                "{$customer->name}'s balance does not match their own ledger",
            );
        }

        foreach (Supplier::all() as $supplier) {
            $this->assertSame(
                (int) AccountTransaction::where('accountable_type', 'supplier')
                    ->where('accountable_id', $supplier->id)->sum('amount'),
                (int) $supplier->balance,
                "{$supplier->name}'s balance does not match their own ledger",
            );
        }
    }

    /** ⚠️ The shelf, counted three ways: the cache, the batches, the movements. */
    public function test_the_shelf_counts_the_same_however_it_is_asked(): void
    {
        foreach (Product::where('kind', '!=', Product::KIND_SERVICE)->get() as $product) {
            $this->assertSame(
                (int) StockMovement::where('product_id', $product->id)->sum('quantity'),
                (int) $product->quantity,
                "{$product->name}'s cached quantity has drifted from its movements",
            );
        }

        $this->assertSame(
            (int) StockMovement::sum('quantity'),
            (int) StockBatch::sum('quantity_remaining'),
            'the batches and the movements disagree about how many units the shop holds',
        );

        $this->assertSame(
            (int) StockMovement::sum(DB::raw(StockMovement::VALUE)),
            (int) StockBatch::sum(DB::raw(StockBatch::VALUE)),
            'the batches and the movements disagree about what the shelf is worth',
        );
    }

    /** A document's total is the sum of its own lines, or it is nothing. */
    public function test_every_invoice_totals_its_own_lines(): void
    {
        foreach (Sale::with('items')->get() as $sale) {
            $this->assertSame(
                (int) $sale->items->sum(fn (SaleItem $line) => $line->quantity * $line->unit_price),
                (int) $sale->total_amount,
                "{$sale->document_no} does not total its own lines",
            );
        }
    }

    // ---- The same question down every route --------------------------------

    /**
     * ⚠️ **The one Soran found.** The Services page has its own SQL and the
     * report goes through `TradeProfit`; over the same window they must give
     * the same figure, or one of the two screens is lying.
     */
    public function test_the_services_page_and_the_profit_report_agree(): void
    {
        foreach ([$this->ever(), $this->thisMonth()] as [$from, $to]) {
            $page = $this->servicesPageFigures($from, $to);
            $report = TradeProfit::between(Product::services(), $from, $to);

            $this->assertSame($report['units'], $page['units'], 'units disagree');
            $this->assertSame($report['revenue'], $page['revenue'], 'earnings disagree');
        }
    }

    /**
     * ⚠️ And the difference between the two windows must be the period ALONE.
     *
     * All time minus this month has to equal what happened before this month —
     * nothing else may leak in. This is the test that would have answered
     * Soran's question in a second instead of an afternoon.
     */
    public function test_all_time_minus_this_month_is_exactly_what_went_before(): void
    {
        [$openedAt, $now] = $this->ever();
        [$monthStart, $today] = $this->thisMonth();

        $ever = TradeProfit::between(Product::query(), $openedAt, $now);
        $month = TradeProfit::between(Product::query(), $monthStart, $today);
        $before = TradeProfit::between(Product::query(), $openedAt, $monthStart->copy()->subDay()->endOfDay());

        $this->assertSame($ever['units'], $month['units'] + $before['units']);
        $this->assertSame($ever['revenue'], $month['revenue'] + $before['revenue']);
        $this->assertSame($ever['cost'], $month['cost'] + $before['cost']);
        $this->assertSame($ever['profit'], $month['profit'] + $before['profit']);

        $this->assertGreaterThan(0, $before['revenue'], 'the fixture must have a past, or this proves nothing');
    }

    /** The report's three kinds must add up to the whole shop, exactly. */
    public function test_the_three_kinds_add_up_to_everything_sold(): void
    {
        [$from, $to] = $this->thisMonth();

        $kinds = array_map(
            fn (string $kind) => TradeProfit::between(Product::ofKind($kind), $from, $to),
            [Product::KIND_STOCK, Product::KIND_USED, Product::KIND_SERVICE],
        );

        $all = TradeProfit::between(Product::query(), $from, $to);

        foreach (['units', 'revenue', 'cost', 'profit'] as $figure) {
            $this->assertSame(
                $all[$figure],
                array_sum(array_column($kinds, $figure)),
                "the three kinds do not add up to the shop's own {$figure}",
            );
        }

        // And each row's own subtraction.
        foreach ($kinds as $kind) {
            $this->assertSame($kind['profit'], $kind['revenue'] - $kind['cost']);
        }
    }

    /** ⚠️ Revenue through the reporting engine, against the raw invoice lines. */
    public function test_revenue_matches_the_invoice_lines_it_is_made_of(): void
    {
        foreach ([$this->ever(), $this->thisMonth()] as [$from, $to]) {
            $engine = TradeProfit::between(Product::query(), $from, $to);

            $raw = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->whereNull('sales.deleted_at')
                ->whereBetween('sales.sale_date', [$from, $to])
                ->selectRaw('SUM(quantity - quantity_returned) as units')
                ->selectRaw('SUM((quantity - quantity_returned) * unit_price) as revenue')
                ->first();

            $this->assertSame((int) $raw->units, $engine['units']);
            $this->assertSame((int) $raw->revenue, $engine['revenue']);
        }
    }

    /**
     * ⚠️ **A service costs nothing, and that must fall out rather than be
     * written down.** It opens no batch and writes no movement, so the cost of
     * the units it sold is zero because there is nothing to sum — not because
     * anything anywhere says "services are free".
     */
    public function test_a_service_has_revenue_and_no_cost_at_all(): void
    {
        $figures = TradeProfit::between(Product::services(), ...$this->ever());

        $this->assertSame(55_000, $figures['revenue'], '11 sold at 5,000');
        $this->assertSame(11, $figures['units']);
        $this->assertSame(0, $figures['cost']);
        $this->assertSame($figures['revenue'], $figures['profit']);

        $this->assertSame(0, StockMovement::where('product_id', $this->service->id)->count());
    }

    /**
     * ⚠️ A returned unit comes off BOTH sides, or a sale given back leaves a
     * profit behind it.
     */
    public function test_a_return_takes_its_revenue_and_its_cost_away_together(): void
    {
        [$from, $to] = $this->thisMonth();

        $before = TradeProfit::between(Product::ofKind(Product::KIND_STOCK), $from, $to);

        $line = Sale::whereHas('returns')->firstOrFail()->items()->firstOrFail();
        $remaining = $line->returnableQuantity();

        app(SaleReturnService::class)->create(
            sale: $line->sale,
            lines: [['sale_item_id' => $line->id, 'quantity' => $remaining]],
            user: User::where('email', 'admin@example.com')->firstOrFail(),
            returnDate: today(), paymentMethod: 'cash',
        );

        $after = TradeProfit::between(Product::ofKind(Product::KIND_STOCK), $from, $to);

        $this->assertSame($before['units'] - $remaining, $after['units']);
        $this->assertSame($before['revenue'] - $remaining * (int) $line->unit_price, $after['revenue']);
        $this->assertLessThan($before['cost'], $after['cost'], 'the cost came off too');
        $this->assertSame($after['revenue'] - $after['cost'], $after['profit']);
    }

    /**
     * ⚠️ **A swap moves cost without moving revenue**, which is the one place
     * the two sides of the profit report are allowed to disagree — the invoice
     * is untouched, and the shop still handed over a second unit.
     */
    public function test_a_swap_costs_the_shop_without_changing_what_it_took(): void
    {
        [$from, $to] = $this->thisMonth();

        $figures = TradeProfit::between(Product::ofKind(Product::KIND_STOCK), $from, $to);
        $swap = Swap::firstOrFail();

        $this->assertGreaterThan(0, $swap->cost(), 'the fixture must swap off a dearer layer');

        /*
         * ⚠️ Asked of the REPORT, not of the movements. A first version summed
         * the swap's own movements and compared them with the swap document —
         * two ways of reading the same two rows, which agree whatever the
         * profit report does with them. Deleting the swap term from
         * `TradeProfit` walked straight through it.
         *
         * So the cost is rebuilt here WITHOUT the swap, and the report's own
         * figure must be exactly that much higher.
         */
        $value = fn (string $reference, string $sign) => (int) StockMovement::query()
            ->whereIn('product_id', Product::ofKind(Product::KIND_STOCK)->select('id'))
            ->where('reference_type', $reference)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum(DB::raw($sign.'('.StockMovement::VALUE.')'));

        $withoutTheSwap = $value(StockMovement::REF_SALE, '-') - $value(StockMovement::REF_SALE_RETURN, '');

        $this->assertSame(
            $withoutTheSwap + $swap->cost(),
            $figures['cost'],
            'the swap is not in the cost of sales, so the shop looks to have earned what it gave away',
        );

        // And revenue did not move: the invoice was never touched.
        $line = SaleItem::findOrFail($swap->sale_item_id);
        $this->assertSame(0, $line->quantity_returned);
        $this->assertSame(1, $line->quantity_swapped);
        $this->assertSame($figures['revenue'] - $figures['cost'], $figures['profit']);
    }

    /**
     * ⚠️ **Through the real page, not only through the query behind it.**
     *
     * The figures agreeing in the database is half the answer; the two screens
     * printing the same number is the other half, and it is the half Soran
     * could see. So this opens both pages over one period and reads the
     * figures off the HTML.
     */
    public function test_the_two_screens_print_the_same_figure(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $period = ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()];

        $expected = TradeProfit::between(Product::services(), ...$this->thisMonth());

        $this->assertSame(15_000, $expected['revenue'], 'three sold at 5,000 this month');

        $services = $this->actingAs($admin)->get(route('services.index', $period));
        $services->assertOk()->assertSee(number_format($expected['revenue']));

        // And the page now says which period it is counting, so the figure can
        // be compared with anything else at all.
        $services->assertSee(today()->startOfMonth()->format(setting('date_format', 'Y-m-d')));

        $this->actingAs($admin)->get(route('reports.index', $period))
            ->assertOk()->assertSee(number_format($expected['revenue']));
    }

    /** All time is still one link away, and says so. */
    public function test_the_services_page_can_still_be_asked_for_all_time(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $ever = TradeProfit::between(Product::services(), ...$this->ever());

        $this->assertSame(55_000, $ever['revenue'], 'eleven sold at 5,000, across two months');

        $this->actingAs($admin)->get(route('services.index', ['all' => 1]))
            ->assertOk()
            ->assertSee(number_format($ever['revenue']))
            ->assertSee('every service ever sold');
    }

    /**
     * The Services page's own SQL, lifted out so the test asks it the way the
     * page does rather than the way the test would like it asked.
     *
     * @return array{units: int, revenue: int}
     */
    private function servicesPageFigures(Carbon $from, Carbon $to): array
    {
        $row = SaleItem::query()
            ->whereIn('product_id', Product::services()->select('id'))
            ->whereHas('sale', fn ($q) => $q->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('SUM(quantity - quantity_returned) as units')
            ->selectRaw('SUM((quantity - quantity_returned) * unit_price) as revenue')
            ->first();

        return ['units' => (int) $row->units, 'revenue' => (int) $row->revenue];
    }
}
