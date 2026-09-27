<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Support\DocumentProfit;
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
        // ⚠️ Paid gave way to Profit on the sales list — Soran, 2026-09-27.
        // Two units at 60,000 off a 40,000 layer, three more the same: five
        // units, 300,000 sold, 200,000 of FIFO cost.
        $this->assertSame(money(100_000), $this->tile($page, __('Profit on these invoices'))['value']);

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
        $this->assertSame(money(60_000), $this->tile($page, __('Profit on these invoices'))['value']);
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

        // ⚠️ **And the profit follows the return too.** 180,000 sold less
        // 60,000 returned is 120,000 of revenue; 120,000 of cost less the
        // 40,000 that came back is 80,000. The returned unit nets to nothing,
        // which is the whole point of subtracting both halves.
        $this->assertSame(money(40_000), $this->tile($page, __('Profit on these invoices'))['value']);
    }

    // ---- Profit per invoice -------------------------------------------------

    /**
     * ⚠️ **The rows must add up to the tile over them.** A column of per-invoice
     * profit beside a total that disagrees with it is the same fault as a
     * breakdown that does not sum to its headline — and this shop has met that
     * fault four times in a week.
     */
    public function test_each_invoices_profit_is_on_its_row_and_they_add_up_to_the_tile(): void
    {
        $this->buy(20, 40_000, 800_000);

        $first = $this->sell(2, 120_000);   // 120,000 sold, 80,000 cost
        $second = $this->sell(3, 180_000);  // 180,000 sold, 120,000 cost

        /*
         * ⚠️ **One of them has a return against it, on purpose.** With no
         * return the tile and the rows agree however either is written, and a
         * sabotage dropping the return from the tile walked straight through
         * the first version of this test.
         */
        app(SaleReturnService::class)->create(
            sale: $first,
            lines: [['sale_item_id' => $first->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: today(), reason: 'One back',
        );

        $page = $this->actingAs($this->user())->get(route('sales.index'))->assertOk();

        $rows = DocumentProfit::perSale(collect([$first->id, $second->id]));

        $this->assertSame(20_000, $rows[$first->id]['profit'], 'one unit left of two');
        $this->assertSame(60_000, $rows[$second->id]['profit']);

        // Both figures are printed, and the tile is exactly their sum.
        $page->assertSee(money(20_000, in: $this->user()->lens()));
        $page->assertSee(money(60_000, in: $this->user()->lens()));
        $this->assertSame(
            money(80_000),
            $this->tile($page, __('Profit on these invoices'))['value'],
            'the rows do not add up to the figure above them',
        );
    }

    /** ⚠️ And a return comes off the row it belongs to, not off the others. */
    public function test_a_return_comes_off_its_own_invoice_only(): void
    {
        $this->buy(20, 40_000, 800_000);

        $returned = $this->sell(2, 120_000);
        $untouched = $this->sell(1, 60_000);

        app(SaleReturnService::class)->create(
            sale: $returned,
            lines: [['sale_item_id' => $returned->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: today(), reason: 'One back',
        );

        $rows = DocumentProfit::perSale(collect([$returned->id, $untouched->id]));

        $this->assertSame(20_000, $rows[$returned->id]['profit'], 'one unit left of two');
        $this->assertSame(20_000, $rows[$untouched->id]['profit'], 'the other invoice did not move');
    }

    /** The invoice's own page says the same thing its row does. */
    public function test_the_invoice_page_prints_what_it_earned(): void
    {
        $this->buy(20, 40_000, 800_000);
        $sale = $this->sell(2, 120_000);

        $this->actingAs($this->user())->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee(__('What this invoice earned'))
            ->assertSee(money(120_000, false))   // revenue
            ->assertSee(money(80_000, false))    // cost
            ->assertSee(money(40_000, false));   // profit
    }

    /**
     * ⚠️ **A marked-up cost would make a plausible profit that is not the
     * shop's**, so anyone who may not see true cost gets the mask — on the
     * tile, on every row, and on the invoice page.
     */
    public function test_a_reader_who_may_not_see_cost_is_shown_no_profit_anywhere(): void
    {
        $this->buy(20, 40_000, 800_000);
        $sale = $this->sell(2, 120_000);

        $counter = User::factory()->create([
            'role' => User::ROLE_USER,
            'cost_visibility' => User::COST_MARKUP,
            'cost_markup_percent' => 20,
        ]);
        $counter->permissions()->sync(
            Permission::whereIn('key', ['sales.view'])->pluck('id')
        );
        $counter = $counter->fresh()->load('permissions');

        $this->assertFalse($counter->seesRealCost());

        $list = $this->actingAs($counter)->get(route('sales.index'))->assertOk();
        $this->assertSame(hidden_money(), $this->tile($list, __('Profit on these invoices'))['value']);

        // ⚠️ Not the marked-up figure, and not the real one either.
        $list->assertDontSee(money(40_000, in: $counter->lens()));

        $this->actingAs($counter)->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee(hidden_money())
            ->assertDontSee(money(40_000, false));
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
