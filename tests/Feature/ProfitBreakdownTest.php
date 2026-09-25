<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SwapService;
use App\Support\ProfitBreakdown;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The full "where the profit came from" sheet, held against the headline —
 * Soran, 2026-09-25: *"other report just show fully where profit are come in
 * to shop, not problem if need more A4 pages"*.
 *
 * ⚠️ **A breakdown that does not add up to the total is worse than none.** It
 * hands a shopkeeper two figures for the same month with no way to tell which
 * to believe — which is exactly the trouble that started this work. So every
 * level of the sheet is summed here and held against `TradeProfit`: the
 * products against the whole shop, the categories against the products, and
 * the individual invoice lines against both.
 */
class ProfitBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Customer $karwan;

    private Supplier $rasan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->buildAMonthOfTrade();
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function window(): array
    {
        return [today()->startOfMonth(), today()->endOfDay()];
    }

    /**
     * Two categories, three kinds, two FIFO layers, a return and a swap — so
     * every term the sheet has to reconcile is actually in the fixture.
     */
    private function buildAMonthOfTrade(): void
    {
        $user = $this->user();
        $accessories = Category::firstOrCreate(['name' => 'Accessories']);
        $consoles = Category::firstOrCreate(['name' => 'Consoles']);

        $this->rasan = Supplier::create(['name' => 'Rasan', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $make = fn (string $sku, string $name, string $kind, int $category, int $buy, int $sell) => Product::create([
            'name' => $name, 'kind' => $kind, 'sku' => $sku,
            'barcode' => $kind === Product::KIND_SERVICE ? null : $sku.'-B',
            'category_id' => $category, 'unit' => $kind === Product::KIND_SERVICE ? 'each' : 'pcs',
            'purchase_price' => $buy, 'sale_price' => $sell, 'quantity' => 0,
        ]);

        $charger = $make('CHG-33W', 'Charger 33W', Product::KIND_STOCK, $accessories->id, 11_000, 18_000);
        $cable = $make('CBL-C60', 'Type-C cable', Product::KIND_STOCK, $accessories->id, 1_400, 2_500);
        $console = $make('PS5-A', 'PS5 slim', Product::KIND_USED, $consoles->id, 700_000, 900_000);
        $pass = $make('SS23', 'Game Pass', Product::KIND_SERVICE, $accessories->id, 0, 5_000);

        $buy = fn (Product $p, int $q, int $price, int $daysAgo) => app(PurchaseService::class)->create(
            supplier: $this->rasan,
            lines: [['product_id' => $p->id, 'quantity' => $q, 'unit_price' => $price]],
            user: $user, purchaseDate: today()->startOfMonth()->addDays($daysAgo), amountPaid: $q * $price,
        );

        // Two layers on the charger, the cheap one sized to run out on the swap.
        $buy($charger, 9, 11_000, 0);
        $buy($charger, 10, 12_000, 1);
        $buy($cable, 50, 1_400, 0);
        $buy($console, 1, 700_000, 1);

        $sell = fn (array $lines, int $daysAgo, int $paid) => app(SaleService::class)->create(
            customer: $this->karwan, lines: $lines, user: $user,
            saleDate: today()->startOfMonth()->addDays($daysAgo),
            amountPaid: $paid, paymentMethod: 'cash',
        );

        $sell([
            ['product_id' => $charger->id, 'quantity' => 4, 'unit_price' => 18_000],
            ['product_id' => $cable->id, 'quantity' => 3, 'unit_price' => 2_500],
        ], 2, 79_500);

        $sell([['product_id' => $pass->id, 'quantity' => 3, 'unit_price' => 5_000]], 3, 15_000);
        $sell([['product_id' => $console->id, 'quantity' => 1, 'unit_price' => 900_000]], 4, 900_000);

        $returned = $sell([['product_id' => $charger->id, 'quantity' => 3, 'unit_price' => 18_000]], 5, 54_000);
        $swapped = $sell([['product_id' => $charger->id, 'quantity' => 2, 'unit_price' => 18_000]], 6, 36_000);

        // The swap first, while the cheap layer is exactly empty (4 + 3 + 2 = 9).
        app(SwapService::class)->create(
            saleItem: $swapped->items()->firstOrFail(), quantity: 2, user: $user,
        );

        app(SaleReturnService::class)->create(
            sale: $returned,
            lines: [['sale_item_id' => $returned->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $user, returnDate: today(), paymentMethod: 'cash',
        );
    }

    /** ⚠️ Every product summed must be the whole shop, to the dinar. */
    public function test_the_products_add_up_to_the_whole_shop(): void
    {
        [$from, $to] = $this->window();

        $rows = (new ProfitBreakdown)->byProduct($from, $to);
        $whole = TradeProfit::between(Product::query(), $from, $to);

        $this->assertGreaterThan(1, $rows->count(), 'the fixture must sell more than one product');

        $this->assertSame($whole['units'], (int) $rows->sum('units'));
        $this->assertSame($whole['revenue'], (int) $rows->sum('revenue'));
        $this->assertSame($whole['cost'], (int) $rows->sum('cost'));
        $this->assertSame($whole['profit'], (int) $rows->sum('profit'));
    }

    /** And the categories must be the products, regrouped and nothing more. */
    public function test_the_categories_add_up_to_the_products(): void
    {
        [$from, $to] = $this->window();

        $breakdown = new ProfitBreakdown;
        $products = $breakdown->byProduct($from, $to);
        $categories = $breakdown->byCategory($products);

        $this->assertGreaterThan(1, $categories->count(), 'the fixture must span more than one category');

        foreach (['units', 'revenue', 'cost', 'profit'] as $figure) {
            $this->assertSame((int) $products->sum($figure), (int) $categories->sum($figure));
        }
    }

    /**
     * ⚠️ **And every invoice line, the deepest level of the sheet.** This is
     * the one that catches a returned line keeping its cost while its money
     * has already come off.
     */
    public function test_the_invoice_lines_add_up_to_the_whole_shop(): void
    {
        [$from, $to] = $this->window();

        $lines = (new ProfitBreakdown)->lines($from, $to);
        $whole = TradeProfit::between(Product::query(), $from, $to);
        $swap = Swap::firstOrFail();

        $this->assertGreaterThan(0, $swap->cost(), 'the fixture must swap off a dearer layer');

        $this->assertSame($whole['units'], (int) $lines->sum('units'));
        $this->assertSame($whole['revenue'], (int) $lines->sum('revenue'));

        /*
         * ⚠️ The lines carry the cost of what was SOLD. The swap's cost belongs
         * to no invoice line — the invoice was never touched — so the shop-wide
         * cost is the lines plus the swap, and saying so here is the whole
         * reconciliation between the deepest level of the sheet and its top.
         */
        $this->assertSame($whole['cost'], (int) $lines->sum('cost') + $swap->cost());
    }

    /** A returned unit takes its cost off the line, not only its money. */
    public function test_a_returned_line_loses_its_cost_as_well_as_its_money(): void
    {
        [$from, $to] = $this->window();

        $line = (new ProfitBreakdown)->lines($from, $to)
            ->first(fn (object $row) => $row->sale->returns()->exists());

        $this->assertNotNull($line);
        $this->assertSame(2, $line->units, 'three sold, one back');
        $this->assertSame(36_000, $line->revenue);
        $this->assertGreaterThan(0, $line->cost, 'the cost of the two that stayed sold');
        $this->assertSame($line->revenue - $line->cost, $line->profit);

        /*
         * Three went out off the 11,000 layer, which still had five; one came
         * back to it. So two units at 11,000 — and the point of the assertion
         * is the subtraction: without taking the returned unit's cost off, the
         * line would read 33,000 against 36,000 of money and look like a
         * disaster instead of an ordinary sale.
         */
        $this->assertSame(22_000, $line->cost);
    }

    /**
     * ⚠️ **Rendered, and its own totals read back off the paper.** A sheet
     * whose engine reconciles but whose table sums a different column is a
     * sheet that lies in print only — and print is where this one is read.
     */
    public function test_the_printed_sheet_opens_and_carries_every_level(): void
    {
        [$from, $to] = $this->window();
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        $whole = TradeProfit::between(Product::query(), $from, $to);

        $page = $this->actingAs($this->user())->get(route('reports.profit', $period));

        $page->assertOk()
            ->assertSee('How the profit was worked out')
            ->assertSee('The three trades')
            ->assertSee('By category')
            ->assertSee('Every product that sold')
            ->assertSee('Every line sold in the period')
            ->assertSee('What came off the profit')
            // The headline revenue has to appear on the sheet that breaks it down.
            ->assertSee(number_format($whole['revenue']))
            // And the names, so the deepest level really is on the paper.
            ->assertSee('Charger 33W')
            ->assertSee('Game Pass')
            ->assertSee('PS5 slim')
            ->assertSee('Accessories')
            ->assertSee('Consoles');
    }

    /** The invoice lines can be left off for a shop with a long month. */
    public function test_the_lines_section_can_be_turned_off(): void
    {
        [$from, $to] = $this->window();

        $this->actingAs($this->user())
            ->get(route('reports.profit', [
                'from' => $from->toDateString(), 'to' => $to->toDateString(), 'lines' => 0,
            ]))
            ->assertOk()
            ->assertSee('Every product that sold')
            ->assertDontSee('Every line sold in the period');
    }

    /** A service has revenue and no cost, wherever it is read. */
    public function test_a_service_carries_its_whole_price_as_profit(): void
    {
        [$from, $to] = $this->window();

        $row = (new ProfitBreakdown)->byProduct($from, $to)
            ->firstOrFail(fn (object $r) => $r->kind === Product::KIND_SERVICE);

        $this->assertSame(15_000, $row->revenue);
        $this->assertSame(0, $row->cost);
        $this->assertSame(15_000, $row->profit);
        $this->assertSame(100, $row->margin);
    }
}
