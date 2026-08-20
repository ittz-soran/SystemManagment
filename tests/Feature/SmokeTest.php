<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page renders. A Blade template only fails at render time, so this is
 * the cheapest way to catch a typo in a view before Soran does.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_every_page_renders_for_an_admin(): void
    {
        [$product, $sale, $purchase, $customer, $supplier] = $this->makeData();

        $pages = [
            route('dashboard'),
            route('products.index'),
            route('products.create'),
            route('products.show', $product),
            route('products.edit', $product),
            route('categories.index'),
            route('suppliers.index'),
            route('suppliers.show', $supplier),
            route('customers.index'),
            route('customers.show', $customer),
            route('users.index'),
            route('users.create'),
            route('users.edit', $this->admin),
            route('sales.index'),
            route('sales.create'),
            route('sales.show', $sale),
            route('purchases.index'),
            route('purchases.create'),
            route('purchases.show', $purchase),
            route('profile.edit'),

            route('sale-returns.index'),
            route('sale-returns.create', $sale),
            route('purchase-returns.index'),
            route('purchase-returns.create', $purchase),

            route('payments.index'),
            route('payments.create', ['payable_type' => 'sale', 'payable_id' => $sale->id]),
            route('payments.create', ['payable_type' => 'purchase', 'payable_id' => $purchase->id]),

            route('sales.print', $sale),
            route('purchases.print', $purchase),
        ];

        foreach ($pages as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk("{$url} should render");
        }
    }

    /** Filters and empty states are separate render paths, so exercise both. */
    public function test_list_filters_and_empty_states_render(): void
    {
        $this->makeData();

        $urls = [
            route('products.index', ['search' => 'nothing-matches-this', 'low_stock' => 1, 'status' => 'active']),
            route('sales.index', ['status' => 'returned', 'from' => '2020-01-01', 'to' => '2020-01-02']),
            route('purchases.index', ['status' => 'returned', 'search' => 'PUR-99999']),
            route('customers.index', ['search' => 'nobody']),
            route('suppliers.index', ['search' => 'nobody']),
        ];

        foreach ($urls as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk("{$url} should render");
        }
    }

    /** Section 9b: the login page is styled from the shop's settings. */
    public function test_the_login_page_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(setting('shop_name'));
    }

    /**
     * Section 2: the interface must render right-to-left for Sorani, Arabic and
     * Persian, with text and direction switching together.
     */
    public function test_pages_render_right_to_left_in_sorani(): void
    {
        $this->admin->forceFill(['language' => 'ckb'])->save();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ckb"', false);
    }

    /**
     * Section 9b: "Only show nav items the user has permission for — never show
     * a link that leads to 'access denied'."
     */
    public function test_the_sidebar_hides_links_the_user_cannot_follow(): void
    {
        $user = User::create([
            'name' => 'Assistant', 'email' => 'a@example.com', 'password' => 'password',
            'role' => User::ROLE_USER, 'is_active' => true,
            'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);

        $user->permissions()->sync(
            \App\Models\Permission::whereIn('key', User::DEFAULT_PERMISSIONS)->pluck('id')
        );

        $response = $this->actingAs($user)->get(route('sales.index'))->assertOk();

        // In the default set, so the link shows.
        $response->assertSee(route('products.index'));

        // Not in the default set, so the link must not appear at all.
        $response->assertDontSee(route('users.index'));
        $response->assertDontSee(route('categories.index'));

        // And the route itself refuses.
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
    }

    /** @return array{0: Product, 1: \App\Models\Sale, 2: \App\Models\Purchase, 3: Customer, 4: Supplier} */
    private function makeData(): array
    {
        $category = Category::create(['name' => 'Flash drives']);

        $product = Product::create([
            'name' => 'Teamgroup 32GB', 'sku' => 'C175', 'barcode' => '4006381333931',
            'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $customer = Customer::create(['name' => 'Rebin Karim']);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin,
            purchaseDate: now(),
            discountAmount: 2_000,
            amountPaid: 50_000,
        );

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin,
            saleDate: now(),
            amountPaid: 10_000,
        );

        return [$product->refresh(), $sale, $purchase, $customer, $supplier];
    }
}
