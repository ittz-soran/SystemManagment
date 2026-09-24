<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssemblyService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Selling the bundle again after taking it apart — Soran, 2026-09-24.
 *
 * *"in sale page can sale main product (What went in) or splited lines (What
 * came out) ... now when i ASM-00001 in sale show 0 pcs Bundle Asus MotherBord
 * however not sale each splited lines"*.
 *
 * ⚠️ **A document read as a recipe.** An assembly records what happened on a
 * day; this asks it what a thing is made of. The latest take-apart of a product
 * is the shop's most recent answer to that question.
 */
class AssemblyRebuildTest extends TestCase
{
    use RefreshDatabase;

    private Product $bundle;

    private Supplier $seller;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->bundle = Product::create([
            'name' => 'Bundle Asus B450M + R5 5500', 'kind' => Product::KIND_STOCK,
            'sku' => 'SS26', 'barcode' => 'SS26-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 238_000, 'sale_price' => 300_000, 'quantity' => 0,
        ]);

        $this->seller = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buyBundles(int $quantity = 1): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $this->bundle->id, 'quantity' => $quantity, 'unit_price' => 238_000]],
            user: $this->user(), purchaseDate: now()->subDays(3), amountPaid: $quantity * 238_000,
        );
    }

    private function split(int $bundles = 1): Assembly
    {
        return app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => $bundles],
            pieces: [
                ['name' => 'Board', 'quantity' => $bundles, 'unit_cost' => 119_000, 'sale_price' => 150_000],
                ['name' => 'CPU', 'quantity' => $bundles, 'unit_cost' => 119_000, 'sale_price' => 150_000],
            ],
            user: $this->user(),
        );
    }

    private function piece(string $name): Product
    {
        return Product::where('name', $name)->firstOrFail();
    }

    // ---- The recipe --------------------------------------------------------

    public function test_the_latest_take_apart_is_the_recipe(): void
    {
        $this->buyBundles(2);
        $first = $this->split();
        $second = $this->split();

        $recipe = app(AssemblyService::class)->recipeFor($this->bundle);

        $this->assertSame($second->id, $recipe->id, 'the shop\'s most recent answer wins');
        $this->assertNotSame($first->id, $recipe->id);
    }

    /**
     * ⚠️ A take-apart that was undone never happened, so it is no recipe.
     *
     * Two things keep it out, and the test says which: the row is soft-deleted,
     * and undoing removes its lines outright. A sabotage that lifted only the
     * soft-delete scope walked through an earlier version of this, because the
     * lines were gone anyway — so both are asserted.
     */
    public function test_a_deleted_take_apart_is_not_a_recipe(): void
    {
        $this->buyBundles();
        $assembly = $this->split();

        app(AssemblyService::class)->delete($assembly, $this->user());

        $this->assertNull(app(AssemblyService::class)->recipeFor($this->bundle->fresh()));
        $this->assertSame(0, app(AssemblyService::class)->rebuildableQuantity($this->bundle->fresh()));

        // Not merely hidden by a scope: its lines are gone, so nothing can read
        // it as a list of what the bundle is made of.
        $this->assertSame(0, AssemblyItem::where('assembly_id', $assembly->id)->count());
        $this->assertNotNull(Assembly::withTrashed()->find($assembly->id), 'the document itself should survive');
    }

    public function test_a_product_never_taken_apart_has_no_recipe(): void
    {
        $this->buyBundles();

        $this->assertNull(app(AssemblyService::class)->recipeFor($this->bundle));
        $this->assertSame(0, app(AssemblyService::class)->rebuildableQuantity($this->bundle));
    }

    /** ⚠️ Limited by the scarcest piece, not by the most plentiful. */
    public function test_it_counts_from_the_shelf_and_the_scarcest_piece_decides(): void
    {
        $this->buyBundles();
        $this->split();

        $service = app(AssemblyService::class);

        $this->assertSame(1, $service->rebuildableQuantity($this->bundle->fresh()));

        // Sell the CPU. One board and no CPU is no bundle at all.
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        $this->assertSame(0, $service->rebuildableQuantity($this->bundle->fresh()));
    }

    /** A recipe written for two bundles at once divides down to one. */
    public function test_a_recipe_for_two_divides(): void
    {
        $this->buyBundles(2);
        $this->split(2);

        $service = app(AssemblyService::class);

        $this->assertSame(2, $service->rebuildableQuantity($this->bundle->fresh()));

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        $this->assertSame(1, $service->rebuildableQuantity($this->bundle->fresh()),
            'one board and two CPUs is one bundle');
    }

    /**
     * ⚠️ Half a piece is not a piece.
     *
     * Two bundles that came apart into three boards say one and a half boards
     * make a bundle. Offering it would have the till take two boards off the
     * shelf and call the result one bundle — which is not what that document
     * says happened, and not a trade the shop agreed to. The till says nothing
     * and the Build screen, where quantities are typed, still does it.
     */
    public function test_a_recipe_that_does_not_divide_offers_nothing(): void
    {
        $this->buyBundles(2);

        app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 2],
            pieces: [
                ['name' => 'Board', 'quantity' => 3, 'unit_cost' => 100_000, 'sale_price' => 150_000],
                ['name' => 'CPU', 'quantity' => 2, 'unit_cost' => 88_000, 'sale_price' => 150_000],
            ],
            user: $this->user(),
        );

        $this->assertSame(3, (int) $this->piece('Board')->quantity);
        $this->assertSame(0, app(AssemblyService::class)->rebuildableQuantity($this->bundle->fresh()),
            'one and a half boards per bundle is not a quantity the till can take off a shelf');
    }

    // ---- Putting it back together -----------------------------------------

    /** ⚠️ Soran's own case, end to end. */
    public function test_the_bundle_can_be_rebuilt_and_costs_what_its_pieces_cost(): void
    {
        $this->buyBundles();
        $this->split();

        $this->assertSame(0, $this->bundle->fresh()->quantity);

        $rebuild = app(AssemblyService::class)->rebuild($this->bundle->fresh(), 1, $this->user());

        $this->assertSame(Assembly::TOGETHER, $rebuild->direction);
        $this->assertSame(238_000, $rebuild->total_cost, 'the bundle is worth what its pieces cost');

        $this->assertSame(1, $this->bundle->fresh()->quantity, 'the bundle did not come back');
        $this->assertSame(0, $this->piece('Board')->quantity);
        $this->assertSame(0, $this->piece('CPU')->quantity);

        // It really is sellable, at the right cost.
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->bundle->id, 'quantity' => 1, 'unit_price' => 300_000]],
            user: $this->user(), saleDate: now(), amountPaid: 300_000, paymentMethod: 'cash',
        );

        $figures = TradeProfit::between(
            Product::whereKey($this->bundle->id), now()->subYear(), now()->addDay(),
        );

        $this->assertSame(300_000, $figures['revenue']);
        $this->assertSame(238_000, $figures['cost'], 'the bundle sold at a made-up cost');
    }

    /** ⚠️ It refuses rather than half-building when a piece is missing. */
    public function test_it_refuses_when_the_pieces_are_not_there(): void
    {
        $this->buyBundles();
        $this->split();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        try {
            app(AssemblyService::class)->rebuild($this->bundle->fresh(), 1, $this->user());
            $this->fail('a bundle was built out of pieces that are not there');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not enough pieces', $e->getMessage());
        }

        $this->assertSame(0, $this->bundle->fresh()->quantity);
        $this->assertSame(1, $this->piece('Board')->quantity, 'the board was taken for a build that failed');
    }

    public function test_it_refuses_a_product_with_no_recipe(): void
    {
        $this->buyBundles();

        $this->expectException(RuntimeException::class);

        app(AssemblyService::class)->rebuild($this->bundle, 1, $this->user());
    }

    /** ⚠️ Nothing is earned or lost by putting it back, either. */
    public function test_rebuilding_leaves_the_stock_value_alone(): void
    {
        $this->buyBundles();
        $this->split();

        $before = (int) StockBatch::sum(StockBatch::raw('quantity_remaining * unit_cost'));

        app(AssemblyService::class)->rebuild($this->bundle->fresh(), 1, $this->user());

        $after = (int) StockBatch::sum(StockBatch::raw('quantity_remaining * unit_cost'));

        $this->assertSame($before, $after);
    }

    // ---- The till ----------------------------------------------------------

    /** ⚠️ The search tells the till a bundle can be had, and what from. */
    public function test_the_till_search_offers_the_bundle_and_names_its_pieces(): void
    {
        $this->buyBundles();
        $this->split();

        $found = $this->actingAs($this->user())
            ->getJson(route('products.search', ['q' => 'SS26', 'rebuildable' => 1]))
            ->assertOk()
            ->json('products.0');

        $this->assertSame(0, $found['quantity'], 'the shelf really is empty');
        $this->assertSame(1, $found['rebuildable']);
        $this->assertEqualsCanonicalizing(['Board', 'CPU'], $found['pieces']);
        $this->assertEqualsCanonicalizing(
            [$this->piece('Board')->id, $this->piece('CPU')->id],
            $found['piece_ids'],
        );
    }

    /** ⚠️ And only when the till asks. A purchase is not rebuilding anything. */
    public function test_the_search_says_nothing_about_rebuilding_unless_asked(): void
    {
        $this->buyBundles();
        $this->split();

        $found = $this->actingAs($this->user())
            ->getJson(route('products.search', ['q' => 'SS26']))
            ->assertOk()
            ->json('products.0');

        $this->assertSame(0, $found['rebuildable']);
        $this->assertSame([], $found['pieces']);
    }

    /** ⚠️ Soran's whole ask, through the till: the bundle sells again. */
    public function test_the_till_sells_a_bundle_that_was_taken_apart(): void
    {
        $this->buyBundles();
        $this->split();

        $this->assertSame(0, $this->bundle->fresh()->quantity);

        $this->actingAs($this->user())->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 300_000,
            'lines' => [['product_id' => $this->bundle->id, 'quantity' => 1, 'unit_price' => 300_000]],
        ])->assertSessionHas('success');

        $sale = Sale::firstOrFail();

        $this->assertSame(300_000, $sale->total_amount);

        // The pieces went into it, and the bundle went out.
        $this->assertSame(0, $this->piece('Board')->quantity);
        $this->assertSame(0, $this->piece('CPU')->quantity);
        $this->assertSame(0, $this->bundle->fresh()->quantity);

        // ⚠️ And it was costed at what its pieces cost, not at a guess.
        $figures = TradeProfit::between(
            Product::whereKey($this->bundle->id), now()->subYear(), now()->addDay(),
        );

        $this->assertSame(238_000, $figures['cost']);
        $this->assertSame(62_000, $figures['profit']);

        // The rebuild is on the record as its own document.
        $this->assertSame(1, Assembly::where('direction', Assembly::TOGETHER)->count());
    }

    /** ⚠️ Only the shortfall: one in stock and two sold rebuilds one. */
    public function test_the_till_rebuilds_only_what_is_short(): void
    {
        $this->buyBundles(2);
        $this->split(1);

        // One bundle whole on the shelf, one in pieces.
        $this->assertSame(1, $this->bundle->fresh()->quantity);

        $this->actingAs($this->user())->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 600_000,
            'lines' => [['product_id' => $this->bundle->id, 'quantity' => 2, 'unit_price' => 300_000]],
        ])->assertSessionHas('success');

        $rebuild = Assembly::where('direction', Assembly::TOGETHER)->firstOrFail();

        $this->assertSame(1, $rebuild->whole()->quantity, 'it rebuilt more than the sale was short of');
        $this->assertSame(0, $this->bundle->fresh()->quantity);
    }

    /**
     * ⚠️ **A sale that fails leaves nothing rebuilt.**
     *
     * The rebuild and the sale are one transaction. Without that, a cart with a
     * bundle and something else the shop has not got would eat the pieces and
     * then refuse — and the shopkeeper would be left with a bundle nobody
     * bought and two pieces gone.
     */
    public function test_a_sale_that_fails_leaves_the_pieces_alone(): void
    {
        $this->buyBundles();
        $this->split();

        $other = Product::create([
            'name' => 'Nothing in stock', 'kind' => Product::KIND_STOCK, 'sku' => 'NONE-1',
            'barcode' => 'NONE-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        $this->actingAs($this->user())->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'lines' => [
                ['product_id' => $this->bundle->id, 'quantity' => 1, 'unit_price' => 300_000],
                ['product_id' => $other->id, 'quantity' => 1, 'unit_price' => 2_000],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Assembly::where('direction', Assembly::TOGETHER)->count(),
            'a bundle was rebuilt for a sale that never happened');
        $this->assertSame(1, $this->piece('Board')->quantity, 'the pieces were eaten by a failed sale');
        $this->assertSame(1, $this->piece('CPU')->quantity);
    }

    /** A product with no recipe still fails on stock, the way it always did. */
    public function test_a_product_with_no_recipe_still_refuses_for_want_of_stock(): void
    {
        $this->buyBundles();

        $this->actingAs($this->user())->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'lines' => [['product_id' => $this->bundle->id, 'quantity' => 5, 'unit_price' => 300_000]],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
    }

    /** Taking apart and putting back together, twice round, still balances. */
    public function test_it_survives_going_round_twice(): void
    {
        $this->buyBundles();

        for ($round = 0; $round < 2; $round++) {
            $this->split();

            $this->assertSame(0, $this->bundle->fresh()->quantity, "round {$round}: the bundle should be apart");

            app(AssemblyService::class)->rebuild($this->bundle->fresh(), 1, $this->user());

            $this->assertSame(1, $this->bundle->fresh()->quantity, "round {$round}: the bundle should be whole");
        }

        // ⚠️ And it is still worth what the shop paid, not a rounding of it.
        $this->assertSame(238_000, (int) $this->bundle->fresh()
            ->stockBatches()->where('quantity_remaining', '>', 0)->value('unit_cost'));
    }
}
