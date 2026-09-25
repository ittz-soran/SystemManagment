<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PeriodArchiveService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The three ways something comes back, as one family — Soran, 2026-09-25:
 * *"make all three purchase-returns, sale-returns, swaps have same designs or
 * same like one and make better ui and data statics"*.
 *
 * ⚠️ **The figures are the part worth testing, not the layout.** A strip of
 * four numbers over a list is read as the truth about that list, and a number
 * that is quietly wrong is worse than no number: it answers the doubt that
 * would have caught it. Every tile here is checked against a shop built to a
 * known shape, and checked again with a filter on.
 */
class GoodsBackFamilyTest extends TestCase
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

    private function buy(int $quantity, int $cost = 40_000, int $daysAgo = 60): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(int $quantity, ?int $paid = null): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today(),
            amountPaid: $paid ?? $quantity * 60_000, paymentMethod: 'cash',
        );
    }

    /**
     * The three parts of one tile, by its label.
     *
     * ⚠️ **`assertSee` on a figure proves less than it looks.** Two sabotages
     * walked through the first version of this file: a swap tile that forgot
     * what came back printed **88,000** where 8,000 belonged, and a cash tile
     * reading the morph column wrongly printed *"120,000 came off what they
     * owed"* where *"0"* belonged — and `assertSee('8,000')` and
     * `assertSee('0 IQD came off…')` are both **substrings of the wrong
     * answer**. A figure has to be read out of its own tile, whole.
     *
     * @return array{label: string, value: string, note: string}
     */
    private function tile(TestResponse $response, string $label): array
    {
        $html = $response->getContent();

        $pattern = '/<span class="stat-tile-label">\s*'.preg_quote($label, '/').'\s*<\/span>'
            .'\s*<span class="stat-tile-value">\s*(.*?)\s*<\/span>'
            .'(?:\s*<span class="stat-tile-note">\s*(.*?)\s*<\/span>)?/s';

        $this->assertMatchesRegularExpression($pattern, $html, "no tile labelled \"{$label}\" on the page");

        preg_match($pattern, $html, $m);

        return [
            'label' => $label,
            'value' => html_entity_decode(strip_tags($m[1])),
            'note' => html_entity_decode(strip_tags($m[2] ?? '')),
        ];
    }

    // ---- The four figures ---------------------------------------------------

    public function test_the_sale_return_list_counts_what_came_back_and_what_left_the_till(): void
    {
        $this->buy(10);
        $sale = $this->sell(5);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            user: $this->user(), returnDate: today(), reason: 'Did not need two',
        );

        $page = $this->actingAs($this->user())->get(route('sale-returns.index'))->assertOk();

        $this->assertSame('1', $this->tile($page, __('Returns'))['value']);
        $this->assertSame('2', $this->tile($page, __('Units back on the shelf'))['value']);

        // Two units at 60,000, paid for in cash and given back in cash.
        $this->assertSame(money(120_000), $this->tile($page, __('Refunded'))['value']);

        // ⚠️ The customer owed nothing, so nothing can have come off a balance.
        // The first version of this tile read the morph column with ::class,
        // matched no payment, and printed exactly that sentence about a
        // customer who had never owed a dinar.
        $cash = $this->tile($page, __('Cash out of the till'));
        $this->assertSame(money(120_000), $cash['value']);
        $this->assertSame(
            __(':amount came off what they owed instead', ['amount' => money(0)]),
            $cash['note'],
        );
    }

    public function test_the_purchase_return_list_counts_what_went_back_and_what_came_as_cash(): void
    {
        $purchase = $this->buy(10, 40_000);

        app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 3]],
            user: $this->user(), returnDate: today(), reason: 'Three arrived scratched',
        );

        $page = $this->actingAs($this->user())->get(route('purchase-returns.index'))->assertOk();

        $this->assertSame('1', $this->tile($page, __('Returns'))['value']);
        $this->assertSame('3', $this->tile($page, __('Units sent back'))['value']);
        $this->assertSame(money(120_000), $this->tile($page, __('Credited'))['value']);

        // Paid for in full, so the credit came back as cash rather than off a
        // balance that was already nothing.
        $cash = $this->tile($page, __('Cash back'));
        $this->assertSame(money(120_000), $cash['value']);
        $this->assertSame(
            __(':amount came off what you owed instead', ['amount' => money(0)]),
            $cash['note'],
        );
    }

    /**
     * ⚠️ **A swap has no total**, so its money tile is the one figure on these
     * three pages that is not a column sum: the replacement less what the
     * supplier gave back.
     */
    public function test_the_swap_list_counts_what_the_shop_is_out_of_pocket(): void
    {
        $this->buy(2, 40_000, 60);
        $this->buy(10, 44_000, 30);
        $sale = $this->sell(2);

        app(SwapService::class)->create($sale->items->first(), 2, $this->user());

        $page = $this->actingAs($this->user())->get(route('swaps.index'))->assertOk();

        $this->assertSame('1', $this->tile($page, __('Swaps'))['value']);
        $this->assertSame('2', $this->tile($page, __('Units handed over again'))['value']);

        // ⚠️ Two replacements off the 44,000 layer against two 40,000 units:
        // 8,000, not the 88,000 the replacements cost on their own.
        $this->assertSame(money(8_000), $this->tile($page, __('What it cost the shop'))['value']);

        $this->assertSame('1', $this->tile($page, __('Billed to a supplier'))['value']);
    }

    /**
     * ⚠️ **The figures follow the filter, and that is the whole reason they can
     * be trusted.** A strip that quietly counted all time while the table under
     * it showed one month is precisely the complaint that opened this week.
     */
    public function test_the_figures_count_only_what_the_filter_shows(): void
    {
        $this->buy(20);
        $sale = $this->sell(10);

        $old = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 4]],
            user: $this->user(), returnDate: today()->subMonthNoOverflow(), reason: 'Old one',
        );

        app(SaleReturnService::class)->create(
            sale: $sale->fresh(),
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: today(), reason: 'New one',
        );

        // Unfiltered: both, and the strip says it is counting everything.
        $all = $this->actingAs($this->user())->get(route('sale-returns.index'))->assertOk();
        $all->assertSee(__('These four count everything on this list. Narrow it below and they follow.'));
        $this->assertSame('2', $this->tile($all, __('Returns'))['value']);
        $this->assertSame(money(300_000), $this->tile($all, __('Refunded'))['value']);

        // Narrowed to today: one return, five units fewer, and the sentence
        // changes to say so.
        $today = $this->actingAs($this->user())->get(route('sale-returns.index', [
            'from' => today()->toDateString(), 'to' => today()->toDateString(),
        ]))->assertOk();

        $today->assertSee(__('These four count only what the filter below is showing.'));
        $today->assertDontSee($old->document_no);
        $this->assertSame('1', $this->tile($today, __('Returns'))['value']);
        $this->assertSame('1', $this->tile($today, __('Units back on the shelf'))['value']);
        $this->assertSame(money(60_000), $this->tile($today, __('Refunded'))['value']);
    }

    // ---- One skeleton -------------------------------------------------------

    /** ⚠️ Every list carries the same furniture, in the same order. */
    public function test_all_three_lists_carry_the_same_furniture(): void
    {
        foreach (['sale-returns.index', 'purchase-returns.index', 'swaps.index'] as $route) {
            $page = $this->actingAs($this->user())->get(route($route))->assertOk();

            $page->assertSee(__('Document number'), false);
            $page->assertSee(__('From'), false);
            $page->assertSee(__('To'), false);
            $page->assertSee(__('Filter'), false);
            $page->assertSee(__('This month'), false);
        }
    }

    /** And every document page does. */
    public function test_all_three_documents_carry_the_same_furniture(): void
    {
        $purchase = $this->buy(20);
        $sale = $this->sell(10);

        $saleReturn = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 2]],
            user: $this->user(), returnDate: today(), reason: 'Two back',
        );

        $purchaseReturn = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 2]],
            user: $this->user(), returnDate: today(), reason: 'Two scratched',
        );

        $swap = app(SwapService::class)->create($sale->fresh()->items->first(), 1, $this->user());

        $pages = [
            route('sale-returns.show', $saleReturn),
            route('purchase-returns.show', $purchaseReturn),
            route('swaps.show', $swap),
        ];

        foreach ($pages as $url) {
            $page = $this->actingAs($this->user())->get($url)->assertOk();

            $page->assertSee(__('Print'), false);
            $page->assertSee(__('The money'), false);
            $page->assertSee(__('Product'), false);
            $page->assertSee(__('Quantity'), false);
            $page->assertSee(__('History'), false);
        }
    }

    /** A swap prints, the same as the two returns do. */
    public function test_a_swap_prints(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 2, $this->user());

        $this->actingAs($this->user())->get(route('swaps.print', $swap))
            ->assertOk()
            ->assertSee($swap->document_no)
            ->assertSee($this->pd->name)
            // ⚠️ On the paper, not only on the screen. A sheet with a document
            // number reads as a bill unless it says otherwise.
            ->assertSee(__('The invoice was not changed. The customer bought it and still owns it — what changed is which unit they have.'));
    }

    // ---- The history --------------------------------------------------------

    /**
     * ⚠️ **A swap is observed from 2026-09-25, and only because its quantity
     * became correctable.** Before that it was written once and never edited,
     * and a history card would have read "nothing recorded yet" forever.
     */
    public function test_correcting_a_swap_is_written_into_its_history(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->actingAs($this->user())->patch(route('swaps.update', $swap), [
            'quantity' => 2, 'note' => 'both dead',
        ])->assertSessionHas('success');

        $page = $this->actingAs($this->user())->get(route('swaps.show', $swap))->assertOk();

        $page->assertSee(__('Quantity'), false);
        $page->assertSee(__('Edited'), false);

        // ⚠️ And NOT the columns the engine rewrites on every save. A reader
        // who corrected one to two wants "Quantity 1 → 2", not a purchase
        // return id they cannot act on.
        $page->assertDontSee('Purchase Return Id');
        $page->assertDontSee(__('Faulty Cost'));
    }

    /** Making one, on the other hand, does not post a second entry a moment later. */
    public function test_making_a_swap_writes_one_entry_not_two(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);

        $this->actingAs($this->user());

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertSame(1, ActivityLog::where('module', 'swaps')
            ->where('record_id', $swap->id)->count(),
            'apply() re-saves the costs, and without the noise list that is a second entry');
    }

    // ---- Archiving ----------------------------------------------------------

    /**
     * ⚠️ **The archive notice on the swap list had to be made true before it
     * could be shown.** Hiding rows a period export never wrote to a file is
     * the one thing archiving must not do.
     */
    public function test_a_swap_is_hidden_by_an_archived_period_and_exported_with_it(): void
    {
        $this->buy(10, 40_000, 400);
        $sale = app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => 3, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today()->subDays(300),
            amountPaid: 180_000, paymentMethod: 'cash',
        );

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());
        $swap->forceFill(['swapped_at' => today()->subDays(300)])->save();

        Setting::updateOrCreate(
            ['key' => 'archived_before'],
            ['value' => today()->subDays(200)->toDateString()],
        );
        Setting::flushCache();

        $page = $this->actingAs($this->user())->get(route('swaps.index'))->assertOk();
        $page->assertDontSee($swap->document_no);

        // And it comes back when asked for.
        $this->actingAs($this->user())->get(route('swaps.index', ['archived' => 1]))
            ->assertOk()->assertSee($swap->document_no);

        // The period export carries swaps, so nothing is hidden that was never
        // written out.
        $this->assertContains('swaps', array_keys(
            (fn () => $this->sheets())->call(app(PeriodArchiveService::class))
        ));
    }
}
