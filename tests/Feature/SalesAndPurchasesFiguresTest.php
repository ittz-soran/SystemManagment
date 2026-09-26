<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Four figures over the sales and purchase lists — Soran, 2026-09-26: *"and for
 * sales, purchases"*.
 *
 * ⚠️ **Each is checked out of its own tile, whole.** Two sabotages walked
 * through the figures written for the goods-back lists because `assertSee` on a
 * number is satisfied by any longer number containing it — 8,000 lives inside
 * 88,000. A figure has to be read from the tile it belongs to.
 */
class SalesAndPurchasesFiguresTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Customer $karwan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD17UK', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** @return array{label: string, value: string, note: string} */
    private function tile(TestResponse $response, string $label): array
    {
        $pattern = '/<span class="stat-tile-label">\s*'.preg_quote($label, '/').'\s*<\/span>'
            .'\s*<span class="stat-tile-value">\s*(.*?)\s*<\/span>'
            .'(?:\s*<span class="stat-tile-note">\s*(.*?)\s*<\/span>)?/s';

        $this->assertMatchesRegularExpression($pattern, $response->getContent(), "no tile labelled \"{$label}\"");

        preg_match($pattern, $response->getContent(), $m);

        return [
            'label' => $label,
            'value' => html_entity_decode(strip_tags($m[1])),
            'note' => html_entity_decode(strip_tags($m[2] ?? '')),
        ];
    }

    private function buy(int $quantity, int $cost, int $paid, int $daysAgo = 10)
    {
        return app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: today()->subDays($daysAgo), amountPaid: $paid,
        );
    }

    private function sell(int $quantity, int $paid, int $daysAgo = 0)
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today()->subDays($daysAgo),
            amountPaid: $paid, paymentMethod: 'cash',
        );
    }

    // ---- Sales --------------------------------------------------------------

    public function test_the_sales_list_counts_what_was_sold_and_what_is_still_due(): void
    {
        $this->buy(20, 40_000, 800_000);

        $this->sell(2, 120_000);   // settled
        $this->sell(3, 100_000);   // 180,000 sold, 80,000 left

        $page = $this->actingAs($this->user())->get(route('sales.index'))->assertOk();

        $this->assertSame('2', $this->tile($page, __('Invoices'))['value']);
        $this->assertSame(money(300_000), $this->tile($page, __('Sold'))['value']);
        $this->assertSame(money(220_000), $this->tile($page, __('Paid'))['value']);

        $due = $this->tile($page, __('Still due'));
        $this->assertSame(money(80_000), $due['value']);
        $this->assertSame(__('on one invoice'), $due['note']);
    }

    /** ⚠️ And they follow every filter the list itself obeys. */
    public function test_the_sales_figures_follow_the_filter(): void
    {
        $this->buy(20, 40_000, 800_000);

        $this->sell(2, 120_000, 40);   // last month
        $this->sell(3, 100_000, 0);    // today

        $page = $this->actingAs($this->user())->get(route('sales.index', [
            'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk();

        $page->assertSee(__('These four count only what the filter below is showing.'));

        $this->assertSame('1', $this->tile($page, __('Invoices'))['value']);
        $this->assertSame(money(180_000), $this->tile($page, __('Sold'))['value']);
        $this->assertSame(money(100_000), $this->tile($page, __('Paid'))['value']);
        $this->assertSame(money(80_000), $this->tile($page, __('Still due'))['value']);
    }

    /**
     * ⚠️ **The customer filter reaches the figures too.** A shopkeeper who
     * narrows to one name is asking what THAT person owes; a strip still
     * counting everybody would answer a question they did not ask.
     */
    public function test_narrowing_to_one_customer_narrows_the_figures(): void
    {
        $this->buy(20, 40_000, 800_000);
        $this->sell(3, 100_000);

        $other = Customer::create(['name' => 'Hawkar', 'phone' => '0751']);
        app(SaleService::class)->create(
            customer: $other,
            lines: [['product_id' => $this->pd->id, 'quantity' => 1, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today(), amountPaid: 60_000, paymentMethod: 'cash',
        );

        $page = $this->actingAs($this->user())->get(route('sales.index', [
            'customer_id' => $this->karwan->id,
        ]))->assertOk();

        $this->assertSame('1', $this->tile($page, __('Invoices'))['value']);
        $this->assertSame(money(180_000), $this->tile($page, __('Sold'))['value']);
        $this->assertSame(money(80_000), $this->tile($page, __('Still due'))['value']);

        // ⚠️ **And the strip SAYS it is narrowed.** The figures follow the
        // customer whether or not `isFiltered()` knows about that box — so a
        // sabotage dropping it from the list changed only the sentence, and
        // the first version of this test did not read the sentence. A strip
        // that counts one customer while claiming to count everything is the
        // unlabelled-scope fault that started this whole week.
        $page->assertSee(__('These four count only what the filter below is showing.'));
        $page->assertDontSee(__('These four count everything on this list. Narrow it below and they follow.'));
    }

    /**
     * ⚠️ **The tile has to equal the column under it.** `Sale::amountDue()`
     * subtracts the credit a return put back as well as the payments, and a
     * strip that forgot the credit would print a bigger figure than the rows
     * it sits over — with nothing on the page to say which to believe. That is
     * the shape of every accounting fault found this week.
     */
    public function test_the_due_tile_equals_the_due_column_when_a_return_has_credited_the_invoice(): void
    {
        $this->buy(20, 40_000, 800_000);
        $sale = $this->sell(3, 100_000);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: today(), reason: 'One back',
        );

        $page = $this->actingAs($this->user())->get(route('sales.index'))->assertOk();

        $rows = Sale::all()->sum(fn ($row) => $row->amountDue());

        $this->assertSame(20_000, $rows, '180,000 sold, 100,000 paid, 60,000 credited back');
        $this->assertSame(money($rows), $this->tile($page, __('Still due'))['value']);

        // And the Paid tile says where the rest of it went.
        $this->assertSame(
            __(':amount more came off as returns', ['amount' => money(60_000)]),
            $this->tile($page, __('Paid'))['note'],
        );
    }

    // ---- Purchases ----------------------------------------------------------

    /**
     * ⚠️ **`grand_total`, not `total_amount`.** The discount comes off the
     * invoice, and the list's own column reads the grand total — a tile on the
     * other field would sum to more than the rows beneath it.
     */
    public function test_the_purchase_list_counts_the_grand_total_after_a_discount(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $this->pd->id, 'quantity' => 10, 'unit_price' => 40_000]],
            user: $this->user(), purchaseDate: today(), amountPaid: 300_000,
            discountAmount: 25_000,
        );

        $page = $this->actingAs($this->user())->get(route('purchases.index'))->assertOk();

        $this->assertSame('1', $this->tile($page, __('Purchases'))['value']);

        // 400,000 less a 25,000 discount is what the shop agreed to pay.
        $this->assertSame(money(375_000), $this->tile($page, __('Bought'))['value']);
        $this->assertSame(money(300_000), $this->tile($page, __('Paid'))['value']);

        $owed = $this->tile($page, __('Still owed'));
        $this->assertSame(money(75_000), $owed['value']);
        $this->assertSame(__('on one purchase'), $owed['note']);
    }

    /** A shop that owes nothing is told so, rather than shown a bare zero. */
    public function test_a_settled_list_says_every_one_is_settled(): void
    {
        $this->buy(10, 40_000, 400_000);

        $page = $this->actingAs($this->user())->get(route('purchases.index'))->assertOk();

        $owed = $this->tile($page, __('Still owed'));
        $this->assertSame(money(0), $owed['value']);
        $this->assertSame(__('every one of them is settled'), $owed['note']);
    }

    /** And the whole family, sales and purchases included, keeps its order. */
    public function test_both_lists_carry_the_strip_above_their_filter(): void
    {
        foreach (['sales.index', 'purchases.index'] as $route) {
            $html = $this->actingAs($this->user())->get(route($route))->assertOk()->getContent();

            $strip = strpos($html, 'stat-tile-label');
            $filter = strpos($html, 'form-label small');

            $this->assertNotFalse($strip, "no figures on {$route}");
            $this->assertNotFalse($filter, "no filter row on {$route}");
            $this->assertLessThan($filter, $strip, "the figures are below the filter on {$route}");
        }
    }
}
