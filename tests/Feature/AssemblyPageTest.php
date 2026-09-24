<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssemblyService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The take-apart and build screens — Soran, 2026-09-24.
 *
 * ⚠️ **These tests RENDER the pages.** The engine is proven elsewhere; what is
 * left to go wrong is the screen — and only rendering catches a Blade comment
 * naming the `@@php` directive, which silently deletes everything down to the
 * next `@@endphp` while the page still answers 200.
 */
class AssemblyPageTest extends TestCase
{
    use RefreshDatabase;

    private Product $bundle;

    private Supplier $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->bundle = Product::create([
            'name' => 'PS5 Slim Digital, box and 2 controllers', 'kind' => Product::KIND_USED,
            'sku' => 'PS5-BUNDLE', 'barcode' => 'PS5-BUNDLE-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 750_000, 'sale_price' => 900_000, 'quantity' => 0,
        ]);

        $this->seller = Supplier::create(['name' => 'Walk-in seller', 'phone' => '0770', 'is_active' => true]);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buyBundle(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $this->bundle->id, 'quantity' => 1, 'unit_price' => 750_000]],
            user: $this->user(), purchaseDate: now()->subDays(3), amountPaid: 750_000,
        );
    }

    private function madeApart(): Assembly
    {
        $this->buyBundle();

        return app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'PS5 Slim Digital with box', 'quantity' => 1, 'unit_cost' => 600_000, 'sale_price' => 700_000],
                ['name' => 'DualSense controller', 'quantity' => 2, 'unit_cost' => 75_000, 'sale_price' => 110_000],
            ],
            user: $this->user(),
            note: 'Customer wanted only one controller',
        );
    }

    // ---- The screens ------------------------------------------------------

    public function test_the_empty_list_says_what_this_is_for(): void
    {
        $this->actingAs($this->user())
            ->get(route('assemblies.index'))
            ->assertOk()
            ->assertSee(__('Nothing taken apart or built yet. Use this when you buy something whole and sell the pieces, or buy pieces and sell one thing.'));
    }

    public function test_the_take_apart_form_offers_what_is_on_the_shelf(): void
    {
        $this->buyBundle();

        $this->actingAs($this->user())
            ->get(route('assemblies.create', ['direction' => 'apart']))
            ->assertOk()
            ->assertSee(__('The thing you are taking apart'))
            ->assertSee('PS5 Slim Digital, box and 2 controllers')
            ->assertSee(__('Share the cost by price'))
            ->assertSee(__('The pieces must be worth exactly what went in. Nothing is earned or lost by opening a box.'));
    }

    /** ⚠️ Building types no cost, and the screen says so rather than hiding it. */
    public function test_the_build_form_does_not_offer_a_cost_box(): void
    {
        $this->buyBundle();

        $this->actingAs($this->user())
            ->get(route('assemblies.create', ['direction' => 'together']))
            ->assertOk()
            ->assertSee(__('What you are building'))
            ->assertSee(__('The sum of the parts. You do not type this — a machine is worth what its parts cost.'), false)
            ->assertDontSee(__('Share the cost by price'));
    }

    /** A shop with nothing on the shelf is told, not left guessing. */
    public function test_an_empty_shelf_says_so(): void
    {
        $this->actingAs($this->user())
            ->get(route('assemblies.create', ['direction' => 'apart']))
            ->assertOk()
            ->assertSee(__('Nothing on the shelf to take apart yet.'));
    }

    public function test_the_form_takes_a_bundle_apart(): void
    {
        $this->buyBundle();

        $response = $this->actingAs($this->user())->post(route('assemblies.store'), [
            'direction' => 'apart',
            'note' => 'Customer wanted only one controller',
            'whole' => ['product_id' => $this->bundle->id, 'quantity' => 1],
            'pieces' => [
                ['name' => 'Console', 'quantity' => 1, 'unit_cost' => 600_000, 'sale_price' => 700_000],
                ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => 75_000, 'sale_price' => 110_000],
            ],
        ]);

        $assembly = Assembly::firstOrFail();

        $response->assertRedirect(route('assemblies.show', $assembly))->assertSessionHas('success');

        $this->assertSame(750_000, $assembly->total_cost);
        $this->assertSame(0, $this->bundle->fresh()->quantity);
    }

    /** ⚠️ A form that does not balance comes back as a message, not a 500. */
    public function test_a_form_that_does_not_balance_says_why(): void
    {
        $this->buyBundle();

        $this->actingAs($this->user())
            ->from(route('assemblies.create'))
            ->post(route('assemblies.store'), [
                'direction' => 'apart',
                'whole' => ['product_id' => $this->bundle->id, 'quantity' => 1],
                'pieces' => [['name' => 'Console', 'quantity' => 1, 'unit_cost' => 700_000]],
            ])
            ->assertRedirect(route('assemblies.create'))
            ->assertSessionHas('error');

        $this->assertSame(0, Assembly::count());
        $this->assertSame(1, $this->bundle->fresh()->quantity);
    }

    public function test_the_document_shows_both_sides_and_says_nothing_was_earned(): void
    {
        $assembly = $this->madeApart();

        $this->actingAs($this->user())
            ->get(route('assemblies.show', $assembly))
            ->assertOk()
            ->assertSee($assembly->document_no)
            ->assertSee('PS5 Slim Digital, box and 2 controllers')
            ->assertSee('DualSense controller')
            ->assertSee(__('Nothing was earned or lost here. What came out is worth exactly what went in — the same :amount, sitting in different places. Your profit report does not count this.', [
                'amount' => money(750_000, false),
            ]), false)
            ->assertSee('Customer wanted only one controller');
    }

    public function test_the_list_describes_what_happened(): void
    {
        $assembly = $this->madeApart();

        $this->actingAs($this->user())
            ->get(route('assemblies.index'))
            ->assertOk()
            ->assertSee($assembly->document_no)
            ->assertSee(__(':thing became :count pieces', [
                'thing' => 'PS5 Slim Digital, box and 2 controllers',
                'count' => '2',
            ]));
    }

    // ---- The fill button --------------------------------------------------

    public function test_the_share_endpoint_returns_costs_per_unit(): void
    {
        $response = $this->actingAs($this->user())->postJson(route('assemblies.share'), [
            'total' => 750_000,
            'lines' => [
                ['value' => 700_000, 'quantity' => 1],
                ['value' => 110_000, 'quantity' => 2],
            ],
        ])->assertOk();

        $costs = $response->json('costs');

        $this->assertSame(750_000, $costs[0] * 1 + $costs[1] * 2, 'the shares do not add up to the whole');
    }

    // ---- Permissions ------------------------------------------------------

    /**
     * ⚠️ Deciding what each piece cost sets the profit on every later sale, so
     * it is its own key — nearer to setting a purchase price than to moving
     * stock about.
     */
    public function test_the_pages_need_their_permissions(): void
    {
        $assembly = $this->madeApart();

        $reader = User::factory()->create(['role' => User::ROLE_USER]);
        $reader->permissions()->sync(Permission::where('key', 'assemblies.view')->pluck('id'));

        $this->actingAs($reader)->get(route('assemblies.index'))->assertOk();
        $this->actingAs($reader)->get(route('assemblies.show', $assembly))->assertOk();
        $this->actingAs($reader)->get(route('assemblies.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('assemblies.store'), [])->assertForbidden();
        $this->actingAs($reader)->postJson(route('assemblies.share'), [])->assertForbidden();

        $stranger = User::factory()->create(['role' => User::ROLE_USER]);
        $stranger->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id'));

        $this->actingAs($stranger)->get(route('assemblies.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('assemblies.show', $assembly))->assertForbidden();
    }

    /** ⚠️ And the product page still opens, which needs the morph alias. */
    public function test_the_product_page_opens_after_a_piece_is_made(): void
    {
        $assembly = $this->madeApart();

        $console = Product::where('name', 'PS5 Slim Digital with box')->firstOrFail();

        $this->actingAs($this->user())
            ->get(route('products.show', $console))
            ->assertOk()
            ->assertSee($assembly->document_no);

        // And the thing it came out of, whose batch was consumed by it.
        $this->actingAs($this->user())
            ->get(route('products.show', $this->bundle))
            ->assertOk()
            ->assertSee($assembly->document_no);
    }
}
