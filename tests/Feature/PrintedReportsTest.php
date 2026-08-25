<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports that go on paper.
 *
 * Set two dates, press a report, and what opens is the sheet itself. What
 * matters most here is not that each one renders — it is that they agree. Two
 * reports covering one month and quoting two different profits is how a
 * shopkeeper stops believing any of them, and this one did exactly that: the
 * sales sheet took the returned money off the sale and charged it the original
 * cost as well, so a returned unit was paid for twice.
 */
class PrintedReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->customer = Customer::create(['name' => 'Karwan']);
        $this->supplier = Supplier::create(['name' => 'Erbil Electronics']);

        $this->product = Product::create([
            'name' => 'USB-C cable',
            'sku' => 'SS-1',
            'category_id' => Category::firstOrCreate(['name' => 'Cables'])->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 3_000,
            'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
            user: $this->admin,
            purchaseDate: now()->subDays(5),
        );
    }

    private function sell(int $quantity = 4)
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 3_000]],
            user: $this->admin,
            saleDate: now()->subDays(2),
            amountPaid: 0,
        );
    }

    /** @return array{0: string, 1: string} */
    private function period(): array
    {
        return ['from' => today()->subMonth()->toDateString(), 'to' => today()->toDateString()];
    }

    public function test_every_report_opens_on_the_printed_layout(): void
    {
        $this->sell();

        foreach (['summary', 'sales', 'purchases', 'customers', 'suppliers'] as $report) {
            $this->actingAs($this->admin)
                ->get(route("reports.{$report}", $this->period()))
                ->assertOk()
                // The print sheet, not the shell: shop letterhead, and none of
                // the navigation. ("app-sidebar" alone would match a CSS rule
                // in the brand block, which every page carries.)
                ->assertSee('print-sheet', false)
                ->assertDontSee('app-sidebar-heading', false)
                ->assertDontSee('id="app-search"', false);
        }
    }

    /**
     * The bug this test exists for.
     *
     * A return takes its money off the sale and puts its cost back on the
     * shelf. Counting only the first half charges the sale twice for a unit it
     * no longer sold, and the sales sheet then disagrees with the summary.
     */
    public function test_the_sales_sheet_and_the_summary_agree_on_profit_after_a_return(): void
    {
        $sale = $this->sell(4);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->admin,
            returnDate: now(),
        );

        // Sold 4 at 3,000 = 12,000, one back = 9,000 revenue.
        // Cost 4 at 1,000 = 4,000, one back = 3,000. Profit 6,000.
        $sales = $this->actingAs($this->admin)
            ->get(route('reports.sales', $this->period()))->assertOk()->getContent();

        $summary = $this->actingAs($this->admin)
            ->get(route('reports.summary', $this->period()))->assertOk();

        $summary->assertViewHas('profit', fn (array $p) => $p['gross_profit'] === 6_000);

        // The same number, on the sheet a shopkeeper would hand somebody.
        $this->assertStringContainsString('6,000', $sales);
    }

    public function test_the_sales_sheet_carries_every_line_of_every_sale(): void
    {
        $this->sell();

        $this->actingAs($this->admin)
            ->get(route('reports.sales', $this->period()))
            ->assertOk()
            ->assertSee('USB-C cable')
            ->assertSee('SS-1');
    }

    /** Totals only, for a month that would otherwise be a ream of paper. */
    public function test_the_lines_can_be_left_off(): void
    {
        $this->sell();

        $this->actingAs($this->admin)
            ->get(route('reports.sales', [...$this->period(), 'detailed' => 0]))
            ->assertOk()
            ->assertDontSee('SS-1');
    }

    /**
     * Section 9: what somebody owes is what they owe today. It carries in from
     * before the period, and cutting it to fit the dates would make the sheet
     * disagree with the person.
     */
    public function test_a_persons_balance_is_todays_not_the_periods(): void
    {
        $this->sell(4);   // 12,000 unpaid

        $this->actingAs($this->admin)
            ->get(route('reports.customers', [
                'from' => today()->subMonth()->toDateString(),
                'to' => today()->subDays(10)->toDateString(),   // before the sale
            ]))
            ->assertOk()
            // Traded nothing in that window, but still owes, so still listed.
            ->assertSee('Karwan')
            ->assertSee('12,000');
    }

    public function test_somebody_who_neither_traded_nor_owes_is_left_off(): void
    {
        Customer::create(['name' => 'Never Been Here']);

        $this->sell();

        $this->actingAs($this->admin)
            ->get(route('reports.customers', $this->period()))
            ->assertOk()
            ->assertDontSee('Never Been Here');
    }

    public function test_a_period_with_nothing_in_it_says_so(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.sales', [
                'from' => today()->subYears(3)->toDateString(),
                'to' => today()->subYears(3)->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('No sales in this period.');
    }
}
