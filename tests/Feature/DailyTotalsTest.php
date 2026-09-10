<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DailyTotals;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The numbers behind the trend chart.
 *
 * Four screens draw from this one class, so a fault here is a fault on all
 * four — and the chart is exactly the kind of thing nobody checks by hand,
 * because a line that is slightly wrong still looks like a line.
 */
class DailyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'Cable', 'sku' => 'C1', 'unit' => 'pcs',
            'category_id' => Category::create(['name' => 'Wires'])->id,
            'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);
    }

    private function totals(): DailyTotals
    {
        return app(DailyTotals::class);
    }

    private function buy(int $quantity, int $price, int $daysAgo): void
    {
        app(PurchaseService::class)->create(
            supplier: Supplier::firstOrCreate(['name' => 'S']),
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->admin, purchaseDate: today()->subDays($daysAgo),
        );
    }

    private function sell(int $quantity, int $price, int $daysAgo)
    {
        return app(SaleService::class)->create(
            customer: Customer::firstOrCreate(['name' => 'C']),
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->admin, saleDate: today()->subDays($daysAgo),
        );
    }

    public function test_every_day_of_the_range_comes_back_including_the_empty_ones(): void
    {
        // Leaving the quiet days out draws a line straight from Thursday to
        // Sunday and makes a closed weekend look like ordinary trade.
        $days = $this->totals()->days(today()->subDays(6), today()->endOfDay());

        $this->assertCount(7, $days);
        $this->assertSame(today()->subDays(6)->toDateString(), $days[0]);
        $this->assertSame(today()->toDateString(), $days[6]);

        $flows = $this->totals()->flows(today()->subDays(6), today()->endOfDay());

        $this->assertCount(7, $flows['sales']);
        $this->assertSame(array_fill(0, 7, 0), array_values($flows['sales']));
    }

    public function test_a_return_lands_on_the_day_it_came_back_not_the_day_of_the_sale(): void
    {
        $this->buy(10, 6_000, 5);
        $sale = $this->sell(2, 10_000, 3);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: today()->subDays(2),
        );

        $flows = $this->totals()->flows(today()->subDays(4), today()->endOfDay());
        $sales = array_values($flows['sales']);

        // Monday's takings were real when they were taken.
        $this->assertSame(20_000, $sales[1], 'the day of the sale keeps its full figure');
        $this->assertSame(-10_000, $sales[2], 'the refund lands on the day it was given');
    }

    public function test_profit_is_revenue_less_the_cost_the_movements_recorded(): void
    {
        $this->buy(10, 6_000, 5);
        $this->sell(2, 10_000, 3);

        $from = today()->subDays(4);
        $to = today()->endOfDay();

        $flows = $this->totals()->flows($from, $to);
        $profit = array_values($this->totals()->profit($flows['sales'], $flows['cost']));

        // 20,000 taken against 12,000 of FIFO cost.
        $this->assertSame(12_000, array_values($flows['cost'])[1]);
        $this->assertSame(8_000, $profit[1]);
    }

    public function test_profit_follows_the_cost_it_is_given_rather_than_the_stored_one(): void
    {
        // Section 2 lets a shop show a counter assistant a marked-up cost. The
        // profit beside it has to be the profit that markup implies, or the
        // real cost is one subtraction away from a screen they were trusted
        // with.
        $this->buy(10, 6_000, 5);
        $this->sell(2, 10_000, 3);

        $flows = $this->totals()->flows(today()->subDays(4), today()->endOfDay());

        $markedUp = array_map(fn (int $cost) => (int) round($cost * 1.25), $flows['cost']);
        $profit = array_values($this->totals()->profit($flows['sales'], $markedUp));

        $this->assertSame(5_000, $profit[1], '20,000 less a 15,000 marked-up cost');
    }

    public function test_stock_value_carries_in_from_before_the_period(): void
    {
        // Today's shelf is everything the shop ever took in less everything it
        // ever let out — a window that started counting at its own first day
        // would say the shop owned nothing.
        $this->buy(10, 6_000, 30);
        $this->sell(4, 10_000, 2);

        $value = $this->totals()->stockValue(today()->subDays(4), today()->endOfDay());
        $value = array_values($value);

        $this->assertSame(60_000, $value[0], 'bought long before the window opened');
        $this->assertSame(60_000, $value[1]);
        $this->assertSame(36_000, $value[2], 'four units left the shelf at 6,000 each');
        $this->assertSame(36_000, $value[4], 'and a level stays where it was left');
    }

    public function test_the_till_is_payments_rather_than_sales(): void
    {
        // A sale on credit is revenue today and cash next month, which is how a
        // shop with good figures still cannot pay a supplier on Thursday.
        $this->buy(10, 6_000, 5);
        $sale = $this->sell(3, 10_000, 3);

        DB::transaction(fn () => app(PaymentService::class)->record(
            $sale, 12_000, Payment::DIRECTION_IN, $this->admin, 'cash', today()->subDay()
        ));

        $from = today()->subDays(4);
        $to = today()->endOfDay();

        $cash = $this->totals()->cash($from, $to);

        $this->assertSame(30_000, array_values($this->totals()->flows($from, $to)['sales'])[1]);
        $this->assertSame(0, array_values($cash['in'])[1], 'nothing was paid on the day of the sale');
        $this->assertSame(12_000, array_values($cash['in'])[3], 'the money arrived two days later');
    }

    public function test_one_product_reports_its_own_units_and_takings(): void
    {
        $other = Product::create([
            'name' => 'Case', 'sku' => 'C2', 'unit' => 'pcs',
            'category_id' => $this->product->category_id,
            'purchase_price' => 0, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        $this->buy(10, 6_000, 5);
        $this->sell(2, 10_000, 3);

        app(PurchaseService::class)->create(
            supplier: Supplier::firstOrCreate(['name' => 'S']),
            lines: [['product_id' => $other->id, 'quantity' => 5, 'unit_price' => 3_000]],
            user: $this->admin, purchaseDate: today()->subDays(5),
        );

        app(SaleService::class)->create(
            customer: Customer::firstOrCreate(['name' => 'C']),
            lines: [['product_id' => $other->id, 'quantity' => 4, 'unit_price' => 5_000]],
            user: $this->admin, saleDate: today()->subDays(3),
        );

        $sold = $this->totals()->forProduct($this->product, today()->subDays(4), today()->endOfDay());

        $this->assertSame(2, array_values($sold['units'])[1], "the other product's four units are not counted here");
        $this->assertSame(20_000, array_values($sold['revenue'])[1]);
        $this->assertSame(0, array_values($sold['units'])[3]);
    }

    public function test_the_date_axis_is_numerals_and_the_weekday_is_the_readers_own(): void
    {
        $axis = $this->totals()->axis(['2026-09-09', '2026-09-10']);

        // Numerals rather than a month name: seven of these sit across a narrow
        // axis and a Kurdish month name is four words long.
        $this->assertSame(['9/9', '10/9'], $axis['labels']);
        $this->assertSame([__('Wednesday'), __('Thursday')], $axis['notes']);
    }
}
