<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SetupProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first hour of a new shop's life.
 *
 * Every shop this is sold to opens on a dashboard of zeros, and zeros tell a
 * shopkeeper nothing about what to do next. The order of the steps is the whole
 * point: stock exists only after a purchase is recorded, so a shop that adds a
 * product and goes straight to selling is told there is none and concludes the
 * system is broken.
 *
 * Built with the real services rather than fixtures, because two of the steps
 * are about what a service leaves behind.
 */
class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function progress(): SetupProgress
    {
        return app(SetupProgress::class);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    private function buy(Product $product): void
    {
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    private function sell(Product $product): Sale
    {
        return app(SaleService::class)->create(
            customer: Customer::firstOrFail(),
            lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 15_000,
        );
    }

    public function test_a_brand_new_shop_is_shown_the_list_with_nothing_done(): void
    {
        $setup = $this->progress();

        $this->assertTrue($setup->shouldShow());
        $this->assertSame(0, $setup->doneCount());
        $this->assertSame('shop', $setup->next()['key']);
    }

    /**
     * The order is the teaching, so it is asserted rather than left to the view.
     *
     * Two of the five are pairs — a category before a product, a supplier
     * before a purchase — because the later form cannot be filled in without
     * the earlier record existing.
     */
    public function test_the_steps_run_in_the_order_the_system_needs(): void
    {
        $keys = array_column($this->progress()->steps(), 'key');

        $this->assertSame(['shop', 'product', 'purchase', 'sale', 'print'], $keys);
    }

    /** Every install is seeded with a name, so a name proves nothing. */
    public function test_the_shop_step_wants_a_phone_number_not_just_the_seeded_name(): void
    {
        $this->assertFalse($this->progress()->steps()[0]['done']);

        Setting::put('shop_phone', '0750 111 2233');

        $this->assertTrue($this->progress()->steps()[0]['done']);
    }

    public function test_a_category_alone_is_not_a_catalogue(): void
    {
        Category::create(['name' => 'Flash drives']);

        $this->assertFalse($this->progress()->steps()[1]['done']);

        $this->product();

        $this->assertTrue($this->progress()->steps()[1]['done']);
    }

    public function test_the_purchase_step_needs_the_supplier_too(): void
    {
        Supplier::create(['name' => 'Bazaar Mobile']);

        $this->assertFalse($this->progress()->steps()[2]['done']);

        $product = $this->product();
        $this->buy($product);

        $this->assertTrue($this->progress()->steps()[2]['done']);
    }

    /**
     * Printing leaves no trace of its own, so the printable view records it.
     *
     * Written at most once — a shop that prints a hundred invoices should not
     * write a hundred settings rows.
     */
    public function test_opening_a_printable_invoice_finishes_the_last_step(): void
    {
        $product = $this->product();
        $this->buy($product);
        $sale = $this->sell($product);

        $this->assertFalse($this->progress()->steps()[4]['done']);

        $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk();

        $this->assertTrue($this->progress()->steps()[4]['done']);

        $first = setting(SetupProgress::PRINTED);

        $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk();

        $this->assertSame($first, setting(SetupProgress::PRINTED), 'recorded once, not on every print');
    }

    public function test_the_card_is_on_an_admin_dashboard_and_says_what_to_do_next(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('Getting your shop going'))
            ->assertSee(__('Put your shop’s name and phone on invoices'), false);
    }

    /**
     * Not shown to an ordinary user.
     *
     * Three of the five steps need permissions they do not have — Settings,
     * suppliers, the catalogue — and a list of instructions somebody cannot
     * follow is worse than no list.
     */
    public function test_an_ordinary_user_is_not_given_instructions_they_cannot_follow(): void
    {
        $user = User::create([
            'name' => 'Karwan', 'email' => 'karwan@shop.iq',
            'password' => 'correct-horse-battery', 'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $user->permissions()->sync(
            Permission::whereIn('key', ['dashboard.view'])->pluck('id'),
        );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('Getting your shop going'));
    }

    public function test_it_can_be_put_away_and_stays_away(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('setup.hide'))
            ->assertRedirect();

        $this->assertFalse($this->progress()->shouldShow());

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('Getting your shop going'));
    }

    /** A shop that has been trading for months is never told to make its first sale. */
    public function test_a_finished_shop_is_not_shown_the_card_at_all(): void
    {
        Setting::put('shop_phone', '0750 111 2233');

        $product = $this->product();
        $this->buy($product);
        $sale = $this->sell($product);

        $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk();

        $setup = $this->progress();

        $this->assertTrue($setup->isComplete());
        $this->assertFalse($setup->shouldShow());
        $this->assertNull($setup->next());

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('Getting your shop going'));
    }
}
