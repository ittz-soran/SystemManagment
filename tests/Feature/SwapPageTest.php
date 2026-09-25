<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The swap screen — Soran, 2026-09-23.
 *
 * *"just select product and do swap and system read stock and let user as
 * option swap same if available or change to other or refund"*.
 *
 * ⚠️ **These tests RENDER the pages.** SwapTest already proves the engine; what
 * is left to go wrong is the screen, and a Blade comment naming the `@php`
 * directive silently deletes everything down to the next `@endphp` — the page
 * answers 200 with the middle missing, and nothing is thrown. Only rendering
 * and reading the output knows the difference.
 */
class SwapPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD-17-UK-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buy(int $quantity, int $cost = 40_000, int $daysAgo = 60): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(int $quantity = 1): Sale
    {
        return Sale::find(app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now()->subDays(5),
            amountPaid: $quantity * 60_000, paymentMethod: 'cash',
        )->id);
    }

    // ---- Finding the thing ------------------------------------------------

    // ---- Which invoice sold it -------------------------------------------

    // ---- The decision ----------------------------------------------------

    // ---- Doing it --------------------------------------------------------

    // ---- Reading it back -------------------------------------------------

    public function test_the_document_shows_the_cost_and_where_the_faulty_one_went(): void
    {
        // Bought at two prices, so the replacement is NOT the same cost as the
        // faulty one and the difference is a real figure rather than zero.
        $this->buy(1, 40_000, daysAgo: 60);
        $this->buy(1, 44_000, daysAgo: 30);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $page = $this->actingAs($this->user())->get(route('swaps.show', $swap));

        $page->assertOk()
            ->assertSee($swap->document_no)
            ->assertSee(__('Back to :supplier', ['supplier' => 'Bazaar Mobile']))
            ->assertSee(PurchaseReturn::firstOrFail()->document_no)
            ->assertSee(money(4_000, false));

        $this->assertSame(4_000, $swap->cost(), 'the swap cost the shop the price rise');
    }

    /**
     * ⚠️ The sign is read, not printed. Off a cheaper layer the shop is AHEAD,
     * and "Out of pocket: −4,000" would say the opposite of what it means.
     */
    public function test_a_cheaper_replacement_reads_as_ahead_rather_than_a_minus(): void
    {
        // The dear one is the older layer, so FIFO hands the faulty unit back
        // at 44,000 and the replacement leaves at 40,000.
        $this->buy(1, 44_000, daysAgo: 60);
        $this->buy(1, 40_000, daysAgo: 30);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertSame(-4_000, $swap->cost());

        $this->actingAs($this->user())
            ->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertSee(__('Ahead by'))
            ->assertDontSee(__('Out of pocket'))
            ->assertSee(money(4_000, false));
    }

    /** One layer, one price: the document says nothing changed hands. */
    public function test_a_swap_off_the_same_layer_costs_nothing(): void
    {
        $this->buy(3, 40_000);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertSame(0, $swap->cost());

        $this->actingAs($this->user())
            ->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertSee(__('Nothing: the replacement cost exactly what the faulty one did.'))
            ->assertDontSee(__('The replacement came off a different layer than the faulty one, so the difference is what prices did in between.'));
    }

    public function test_the_list_shows_the_swap(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->actingAs($this->user())
            ->get(route('swaps.index'))
            ->assertOk()
            ->assertSee($swap->document_no)
            ->assertSee($sale->document_no)
            ->assertSee(PurchaseReturn::firstOrFail()->document_no);
    }

    public function test_the_empty_list_is_an_instruction(): void
    {
        $this->actingAs($this->user())
            ->get(route('swaps.index'))
            ->assertOk()
            ->assertSee(__('Swap a faulty item'));
    }

    /**
     * ⚠️ The transfer lesson, learnt again. A swap writes movements whose
     * `reference_type` reads 'swap', and the product page resolves that column
     * as a relation — without the morph alias it dies there, and only after a
     * shop has actually swapped something.
     */
    public function test_the_product_page_still_opens_after_a_swap(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->actingAs($this->user())
            ->get(route('products.show', $this->pd))
            ->assertOk()
            ->assertSee($swap->document_no);
    }

    // ---- The other two ways out ------------------------------------------

    /**
     * *"after select one open it on sale return and marked as wanted product to
     * return"* — the line arrives already filled in and already ticked.
     */
    public function test_the_return_screen_arrives_with_the_line_marked(): void
    {
        $this->buy(2);
        $sale = $this->sell(1);
        $line = $sale->items->first();

        $page = $this->actingAs($this->user())
            ->get(route('sale-returns.create', ['sale' => $sale, 'line' => $line->id]));

        $page->assertOk();

        // The box for THIS line starts at one, and the faulty tick is on.
        $this->assertMatchesRegularExpression(
            '/name="lines\[0\]\[quantity\]"[^>]*value="1"/',
            $page->getContent(),
            'the line the reader chose was not filled in',
        );
        $this->assertMatchesRegularExpression(
            '/id="faulty-'.$line->id.'"[^>]*checked/',
            $page->getContent(),
            'the faulty tick was not turned on',
        );
    }

    /** Without the parameter nothing is filled in — the old behaviour stands. */
    public function test_the_return_screen_is_untouched_without_the_parameter(): void
    {
        $this->buy(2);
        $sale = $this->sell(1);

        $page = $this->actingAs($this->user())->get(route('sale-returns.create', $sale));

        $page->assertOk();

        $this->assertMatchesRegularExpression(
            '/name="lines\[0\]\[quantity\]"[^>]*value="0"/',
            $page->getContent(),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="faulty-'.$sale->items->first()->id.'"[^>]*checked/',
            $page->getContent(),
        );
    }

    /** ⚠️ A line id from somebody else's invoice must not mark anything. */
    public function test_a_line_from_another_invoice_marks_nothing(): void
    {
        $this->buy(4);
        $mine = $this->sell(1);
        $theirs = $this->sell(1);

        $page = $this->actingAs($this->user())
            ->get(route('sale-returns.create', ['sale' => $mine, 'line' => $theirs->items->first()->id]));

        $page->assertOk();

        $this->assertMatchesRegularExpression(
            '/name="lines\[0\]\[quantity\]"[^>]*value="0"/',
            $page->getContent(),
            'a line from another invoice filled this one in',
        );
    }

    // ---- Permissions -----------------------------------------------------

    public function test_the_pages_need_their_permissions(): void
    {
        $this->buy(2);
        $sale = $this->sell(1);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $reader = User::factory()->create(['role' => User::ROLE_USER]);
        $reader->permissions()->sync(Permission::where('key', 'swaps.view')->pluck('id'));

        // A reader may read, and may not do.
        $this->actingAs($reader)->get(route('swaps.index'))->assertOk();
        $this->actingAs($reader)->get(route('swaps.show', $swap))->assertOk();

        // And may not make one. Swaps are started on `Goods coming back` now,
        // so that is where the doing key is asked for.
        $this->actingAs($reader)->post(route('goods-back.swap'), [
            'sale_item_id' => $sale->items->first()->id, 'quantity' => 1,
        ])->assertForbidden();

        // And somebody with neither key sees none of it.
        $stranger = User::factory()->create(['role' => User::ROLE_USER]);
        $stranger->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id'));

        $this->actingAs($stranger)->get(route('swaps.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('swaps.show', $swap))->assertForbidden();

        $this->assertSame(1, Swap::count());
    }

    /** The menu entry follows the same key. */
    public function test_the_menu_shows_swaps_only_to_somebody_who_may_read_them(): void
    {
        $reader = User::factory()->create(['role' => User::ROLE_USER]);
        $reader->permissions()->sync(Permission::whereIn('key', ['swaps.view', 'sales.view'])->pluck('id'));

        $this->actingAs($reader)->get(route('swaps.index'))->assertOk()
            ->assertSee(route('swaps.index'), false);

        $stranger = User::factory()->create(['role' => User::ROLE_USER]);
        $stranger->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id'));

        $this->actingAs($stranger)->get(route('sales.index'))->assertOk()
            ->assertDontSee(route('swaps.index'), false);
    }
}
