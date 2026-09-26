<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DailyTotals;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Support\ProfitBreakdown;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale on one day, its return on the next — Soran, 2026-09-26.
 *
 * *"24/9 to 24/9 and 25/9 to 25/9 and both"*. He sold on the 24th, the customer
 * brought one line back on the 25th, and he read the same shop three ways. The
 * two days added up, and the day on its own did not agree with itself.
 *
 * ⚠️ **The shop has two clocks, and one report was reading both at once.**
 *
 * - The **period** clock asks *what happened between these dates*: the sale is
 *   the 24th's, the return is the 25th's. `ReportController::profit()` — the
 *   chain in section 1 and the four tiles — is on this clock, both sides of it.
 * - The **cohort** clock asks *how did the sales made between these dates turn
 *   out*: the return comes off the 24th whenever it happened. The sales report
 *   is on this clock and says so in its own subtitle.
 *
 * Both are worth having. `TradeProfit` was on neither: its revenue subtracted
 * `sale_items.quantity_returned`, which is a CURRENT column and knows no dates,
 * while its cost subtracted only the return movements inside the window. One
 * half of the subtraction landed and the other did not.
 */
class ReturnAcrossTheWindowTest extends TestCase
{
    use RefreshDatabase;

    private Product $cooler;

    private Product $cable;

    private Customer $karwan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $category = Category::first();
        $supplier = Supplier::create(['name' => 'Rasan', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $this->cooler = Product::create([
            'name' => 'Phone Cooler', 'kind' => Product::KIND_STOCK, 'sku' => 'SP-R01',
            'barcode' => 'SPR01', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 12_500, 'sale_price' => 23_000, 'quantity' => 0,
        ]);

        $this->cable = Product::create([
            'name' => 'Cable 5A', 'kind' => Product::KIND_STOCK, 'sku' => 'CX-6M',
            'barcode' => 'CX6M', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 2_000, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [
                ['product_id' => $this->cooler->id, 'quantity' => 5, 'unit_price' => 12_500],
                ['product_id' => $this->cable->id, 'quantity' => 5, 'unit_price' => 2_000],
            ],
            user: $this->user(), purchaseDate: $this->day(-10), amountPaid: 72_500,
        );
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function day(int $offset)
    {
        return today()->copy()->addDays($offset);
    }

    /** Sold on the 24th: a cooler at 23,000 and a cable at 5,000. */
    private function sellOnTheFirstDay(): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [
                ['product_id' => $this->cooler->id, 'quantity' => 1, 'unit_price' => 23_000],
                ['product_id' => $this->cable->id, 'quantity' => 1, 'unit_price' => 5_000],
            ],
            user: $this->user(), saleDate: $this->day(-2), amountPaid: 28_000, paymentMethod: 'cash',
        );
    }

    /** The cooler comes back the next day. */
    private function returnOnTheSecondDay(Sale $sale): void
    {
        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [[
                'sale_item_id' => $sale->items->firstWhere('product_id', $this->cooler->id)->id,
                'quantity' => 1,
            ]],
            user: $this->user(), returnDate: $this->day(-1), reason: 'Did not want it',
        );
    }

    private function trade(int $fromOffset, int $toOffset): array
    {
        return TradeProfit::between(
            Product::ofKind(Product::KIND_STOCK),
            $this->day($fromOffset)->startOfDay(),
            $this->day($toOffset)->endOfDay(),
        );
    }

    /**
     * ⚠️ **The day on its own has to agree with itself.**
     *
     * On the 24th the shop sold 28,000 of goods and nothing had come back yet.
     * The period clock says revenue 28,000 against a cost of 14,500 — the
     * cooler's 12,500 and the cable's 2,000 — so 13,500.
     *
     * The broken version answered 5,000 − 14,500 = **−9,500**: a day the shop
     * made money, reported as a loss, because the revenue had already been
     * given back and the cost had not.
     */
    public function test_the_first_day_alone_does_not_give_back_a_refund_that_has_not_happened(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $figures = $this->trade(-2, -2);

        $this->assertSame(28_000, $figures['revenue'], 'the 23,000 was earned on this day');
        $this->assertSame(14_500, $figures['cost']);
        $this->assertSame(13_500, $figures['profit']);
        $this->assertSame(2, $figures['units']);
    }

    /** And the second day carries the return, both halves of it. */
    public function test_the_second_day_carries_the_whole_refund(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $figures = $this->trade(-1, -1);

        $this->assertSame(-23_000, $figures['revenue'], 'the refund belongs to the day it was given');
        $this->assertSame(-12_500, $figures['cost'], 'and so does the cost that came back with it');
        $this->assertSame(-10_500, $figures['profit']);
        $this->assertSame(-1, $figures['units']);
    }

    /** ⚠️ And the two days must add up to the two days together. */
    public function test_the_days_add_up_to_the_window_that_holds_both(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $first = $this->trade(-2, -2);
        $second = $this->trade(-1, -1);
        $both = $this->trade(-2, -1);

        foreach (['units', 'revenue', 'cost', 'profit'] as $figure) {
            $this->assertSame(
                $first[$figure] + $second[$figure],
                $both[$figure],
                "the two days do not add up to the window that holds them: {$figure}",
            );
        }

        // The cooler nets to nothing over the pair; the cable is the whole of it.
        $this->assertSame(5_000, $both['revenue']);
        $this->assertSame(2_000, $both['cost']);
        $this->assertSame(3_000, $both['profit']);
        $this->assertSame(1, $both['units']);
    }

    /**
     * ⚠️ **Section 5 has to carry a refund whose sale is not in the window**,
     * or the deepest level of the sheet stops adding up to the top of it — the
     * one thing the sheet promises. Read over the second day alone, there is no
     * line sold that day at all: only the cooler coming back.
     */
    public function test_a_refund_against_an_older_sale_still_appears_and_adds_up(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $lines = app(ProfitBreakdown::class)->lines(
            $this->day(-1)->startOfDay(),
            $this->day(-1)->endOfDay(),
        );

        $this->assertCount(1, $lines, 'the refund has no line of its own without this');

        $row = $lines->first();

        $this->assertSame(-1, $row->units);
        $this->assertSame(-23_000, $row->revenue);
        $this->assertSame(-12_500, $row->cost);
        $this->assertTrue($row->refund_only, 'the row must say it was sold before this period');

        // And it adds up to what the three trades say for the same day.
        $figures = $this->trade(-1, -1);
        $this->assertSame($figures['revenue'], (int) $lines->sum('revenue'));
        $this->assertSame($figures['cost'], (int) $lines->sum('cost'));
    }

    /**
     * ⚠️ **A product's own chart had the same fault**, and there it changed the
     * shape of the week rather than a total: a unit sold on Monday and brought
     * back on Friday vanished from Monday's bar, so a busy Monday read quiet.
     */
    public function test_a_products_daily_chart_takes_the_refund_off_the_day_it_came_back(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $daily = app(DailyTotals::class)->forProduct(
            $this->cooler,
            $this->day(-2)->startOfDay(),
            $this->day(-1)->endOfDay(),
        );

        $first = $this->day(-2)->toDateString();
        $second = $this->day(-1)->toDateString();

        $this->assertSame(1, $daily['units'][$first], 'the day it sold shows the sale');
        $this->assertSame(23_000, $daily['revenue'][$first]);
        $this->assertSame(-1, $daily['units'][$second], 'the day it came back shows the refund');
        $this->assertSame(-23_000, $daily['revenue'][$second]);
    }

    /**
     * ⚠️ **And the report's own sections must agree with its headline.**
     *
     * Section 1 is the period clock and always was. Sections 2 to 5 read
     * `TradeProfit`. On a day with a return on the other side of the window,
     * the sheet used to print one figure at the top and a different one three
     * lines below it.
     */
    public function test_the_profit_sheet_agrees_with_its_own_headline_on_a_single_day(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $page = $this->actingAs($this->user())->get(route('reports.profit', [
            'from' => $this->day(-2)->toDateString(),
            'to' => $this->day(-2)->toDateString(),
        ]))->assertOk();

        $html = $page->getContent();

        // The chain's gross profit, and the "Together" row of the three trades.
        $this->assertStringContainsString(money(13_500, false), $html);
        $this->assertStringNotContainsString(money(-9_500, false), $html);
    }

    /**
     * ⚠️ **A share of a loss is not a share.** A day holding nothing but a
     * refund has a negative total, and the column divided by `max(1, total)` —
     * so one product's −10,500 printed as **−1,050,000%**. A figure like that
     * on a page of otherwise sound arithmetic is how a shopkeeper stops
     * believing the sound arithmetic.
     */
    public function test_a_day_that_only_lost_money_does_not_print_a_share(): void
    {
        $sale = $this->sellOnTheFirstDay();
        $this->returnOnTheSecondDay($sale);

        $page = $this->actingAs($this->user())->get(route('reports.profit', [
            'from' => $this->day(-1)->toDateString(),
            'to' => $this->day(-1)->toDateString(),
        ]))->assertOk();

        $page->assertDontSee('-1050000%');
        $page->assertDontSee('1050000');

        // The row is still there, with its real figures.
        $page->assertSee(money(-10_500, false));
    }
}
