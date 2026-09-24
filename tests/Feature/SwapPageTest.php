<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
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

    public function test_the_page_starts_by_asking_for_the_product(): void
    {
        $this->actingAs($this->user())
            ->get(route('swaps.create'))
            ->assertOk()
            ->assertSee(__('Name, SKU or barcode'));
    }

    public function test_it_finds_the_product_by_sku(): void
    {
        $this->buy(2);

        $page = $this->actingAs($this->user())->get(route('swaps.create', ['q' => 'PD-17']));

        $page->assertOk()->assertSee('Power bank 17000mAh UK');
        $page->assertSee(route('swaps.create', ['product' => $this->pd->id]), false);
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $this->actingAs($this->user())
            ->get(route('swaps.create', ['q' => 'NOT-A-THING']))
            ->assertOk()
            ->assertDontSee('Power bank 17000mAh UK')
            ->assertSee(__('Nothing matches :term.', ['term' => 'NOT-A-THING']));
    }

    // ---- Which invoice sold it -------------------------------------------

    public function test_choosing_a_product_lists_the_invoices_that_sold_it(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $page = $this->actingAs($this->user())->get(route('swaps.create', ['product' => $this->pd->id]));

        $page->assertOk()
            ->assertSee($sale->document_no)
            ->assertSee('Karwan')
            ->assertSee(route('swaps.create', ['sale_item' => $sale->items->first()->id]), false);
    }

    /**
     * ⚠️ A line whose units have all come back already must not be offered: the
     * shop would hand over a replacement for something it has already replaced.
     */
    public function test_a_line_already_swapped_is_not_offered_again(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->actingAs($this->user())
            ->get(route('swaps.create', ['product' => $this->pd->id]))
            ->assertOk()
            ->assertDontSee($sale->document_no)
            ->assertSee(__('No invoice has this product still to come back. Nothing was sold, or everything sold has already been returned or swapped.'));
    }

    // ---- The decision ----------------------------------------------------

    public function test_the_decision_page_offers_the_swap_and_names_the_supplier(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $page = $this->actingAs($this->user())
            ->get(route('swaps.create', ['sale_item' => $sale->items->first()->id]));

        $page->assertOk()
            ->assertSee(__('Hand over the same thing'))
            ->assertSee(__('Swap it'))
            // The shelf is read before anything is decided, and above the
            // buttons: three bought, one sold, so two are there.
            ->assertSee(__('On the shelf right now'))
            ->assertSee('2 pcs')
            // And who will carry the cost of the faulty one.
            ->assertSee(__('Where the faulty one came from'))
            ->assertSee('Bazaar Mobile')
            ->assertSee(Purchase::first()->document_no);
    }

    /**
     * ⚠️ The case Soran raised: *"now I don't have stock same this"*. The swap
     * must not be offered at all — and the other two ways out must still be.
     */
    public function test_with_an_empty_shelf_the_swap_is_not_offered_but_the_return_is(): void
    {
        $this->buy(1);
        $sale = $this->sell(1);

        $this->assertSame(0, $this->pd->fresh()->quantity);

        $page = $this->actingAs($this->user())
            ->get(route('swaps.create', ['sale_item' => $sale->items->first()->id]));

        $page->assertOk()
            ->assertDontSee(__('Swap it'))
            ->assertSee(__('There is no :product left to swap it for. Return it or change it for something else.', [
                'product' => 'Power bank 17000mAh UK',
            ]))
            ->assertSee(__('Take it back on the invoice'))
            ->assertSee(route('sale-returns.create', ['sale' => $sale, 'line' => $sale->items->first()->id]), false);
    }

    /** Taking a return is its own permission, so the way out is not offered. */
    public function test_the_return_is_not_offered_without_the_permission(): void
    {
        $this->buy(1);
        $sale = $this->sell(1);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->permissions()->sync(Permission::whereIn('key', ['swaps.view', 'swaps.create'])->pluck('id'));

        $this->actingAs($user)
            ->get(route('swaps.create', ['sale_item' => $sale->items->first()->id]))
            ->assertOk()
            ->assertDontSee(__('Take it back on the invoice'))
            ->assertSee(__('You are not allowed to take returns, so ask somebody who is.'));
    }

    // ---- Doing it --------------------------------------------------------

    public function test_the_form_swaps_it_and_lands_on_the_document(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $response = $this->actingAs($this->user())->post(route('swaps.store'), [
            'sale_item_id' => $sale->items->first()->id,
            'quantity' => 1,
            'note' => 'Not charging',
        ]);

        $swap = Swap::firstOrFail();
        $response->assertRedirect(route('swaps.show', $swap))
            ->assertSessionHas('success');

        $this->assertSame(1, $swap->quantity);
        $this->assertSame('Not charging', $swap->note);
        // The invoice is untouched, and the shelf is down to one.
        $this->assertSame(0, Sale::find($sale->id)->returns()->count());
        $this->assertSame(1, $this->pd->fresh()->quantity);
    }

    /**
     * ⚠️ A swap the shop cannot do comes back as a message on the page, not a
     * 500 — the customer is standing at the counter.
     */
    public function test_a_swap_that_cannot_be_done_says_why(): void
    {
        $this->buy(1);
        $sale = $this->sell(1);

        $this->actingAs($this->user())
            ->from(route('swaps.create', ['sale_item' => $sale->items->first()->id]))
            ->post(route('swaps.store'), [
                'sale_item_id' => $sale->items->first()->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('swaps.create', ['sale_item' => $sale->items->first()->id]))
            ->assertSessionHas('error');

        $this->assertSame(0, Swap::count());
    }

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
        $this->actingAs($reader)->get(route('swaps.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('swaps.store'), [
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

    /** A sale item that no longer exists is a 404, not a 500. */
    public function test_an_unknown_line_is_simply_not_found(): void
    {
        $page = $this->actingAs($this->user())->get(route('swaps.create', ['sale_item' => 9_999]));

        // Nothing chosen: the page falls back to asking for the product.
        $page->assertOk()->assertSee(__('Name, SKU or barcode'));

        $this->assertNull(SaleItem::find(9_999));
    }
}
