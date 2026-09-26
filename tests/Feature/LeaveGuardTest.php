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
 * A cart is not left by accident — Soran, 2026-09-26.
 *
 * *"when counter or user on page sale/purchase and already added item to cart
 * -> should not go another where or page until complete or show warning message
 * to close or stay"*.
 *
 * ⚠️ **The behaviour itself is JavaScript and a browser proved it**: an empty
 * cart navigates freely; one line makes the menu ask; Stay keeps the cart; Leave
 * and lose it goes; Save and Hold do not ask; the number pad still opens; and on
 * the edit screen an untouched cart is silent while a changed quantity asks.
 *
 * What a feature test can hold is the wiring those behaviours need, and it is
 * worth holding because every piece of it is easy to drop silently:
 *
 * - the modal is on the page, or the click has nothing to show;
 * - the page assigns the predicate, or the guard watches nothing;
 * - the Hold button releases it, or the one button whose job is to keep the
 *   cart asks whether you mind losing it;
 * - and the guard is in the COMPILED bundle, because app.js being right is not
 *   the same as the build being current.
 */
class LeaveGuardTest extends TestCase
{
    use RefreshDatabase;

    /** The two screens that hold a cart. Both edit views @include these. */
    private const CART_SCREENS = ['sales.create', 'purchases.create'];

    private Product $pd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD17UK', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_both_cart_screens_carry_the_question_and_both_its_answers(): void
    {
        foreach (self::CART_SCREENS as $route) {
            $page = $this->actingAs($this->user())->get(route($route))->assertOk();

            $page->assertSee('id="leave-guard"', false);
            $page->assertSee(__('There is something in the cart'));
            $page->assertSee(__('Leaving now loses what has been scanned. Nothing has been saved yet.'));

            // ⚠️ Both answers, and the way out the shop already has.
            $page->assertSee(__('Stay on this page'));
            $page->assertSee(__('Leave and lose it'));
            $page->assertSee(__('To keep it for later without finishing it, stay here and press Hold this cart.'));
            $page->assertSee('data-leave-anyway', false);
        }
    }

    /**
     * ⚠️ **The predicate is assigned, not handed to `watch()`.** app.js is a
     * module and the browser defers it, so a cart page's inline script runs
     * first — `window.appLeaveGuard` does not exist yet, optional chaining
     * swallows the call, and the guard silently watches nothing. That is
     * exactly what happened, and only a browser showed it.
     */
    public function test_both_cart_screens_assign_the_predicate_rather_than_calling_the_guard(): void
    {
        foreach (self::CART_SCREENS as $route) {
            $page = $this->actingAs($this->user())->get(route($route))->assertOk();

            $page->assertSee('window.appUnsavedWork =', false);
            $page->assertDontSee('appLeaveGuard?.watch', false);
        }
    }

    /** ⚠️ And it compares against a snapshot, so an untouched edit is silent. */
    public function test_the_guard_is_a_comparison_against_what_the_screen_opened_with(): void
    {
        foreach (self::CART_SCREENS as $route) {
            $this->actingAs($this->user())->get(route($route))
                ->assertOk()
                ->assertSee('snapshot() !== pristine', false);
        }
    }

    /**
     * ⚠️ Holding a cart IS saving it. Without this the one button whose whole
     * job is to keep the cart would ask whether you minded losing it.
     */
    public function test_holding_a_cart_releases_the_guard_before_it_navigates(): void
    {
        foreach (self::CART_SCREENS as $route) {
            $body = $this->actingAs($this->user())->get(route($route))->assertOk()->getContent();

            $release = strpos($body, 'window.appLeaveGuard?.release();');
            $navigate = strpos($body, 'window.location = ');

            $this->assertNotFalse($release, "no release on {$route}");
            $this->assertNotFalse($navigate, "no redirect on {$route}");
            $this->assertLessThan($navigate, $release,
                "the guard is released after the redirect on {$route}, which is never");
        }
    }

    /**
     * ⚠️ **Read from the compiled bundle, not from `resources/js`.** That is
     * what a browser is handed, and app.js being right is not the same as the
     * build being current — the same reason `CartRowTest` reads the compiled
     * stylesheet.
     */
    public function test_the_guard_is_in_the_bundle_the_browser_is_handed(): void
    {
        $js = '';

        foreach (glob(public_path('build/assets/*.js')) as $path) {
            $js .= file_get_contents($path);
        }

        $this->assertNotSame('', $js, 'No compiled bundle. Run `npm run build` first.');

        foreach (['appLeaveGuard', 'appUnsavedWork', 'beforeunload', 'leave-guard'] as $needle) {
            $this->assertStringContainsString($needle, $js,
                $needle.' is missing from the built bundle, so the guard the shop is served does nothing.');
        }
    }

    /**
     * ⚠️ **And nowhere else.** A guard on a page with nothing to lose would ask
     * a shopkeeper to confirm leaving a list — which is how somebody learns to
     * press "Leave" without reading, and then it catches nothing.
     */
    public function test_a_screen_with_no_cart_does_not_carry_the_guard(): void
    {
        foreach (['sales.index', 'purchases.index', 'products.index', 'dashboard'] as $route) {
            $this->actingAs($this->user())->get(route($route))
                ->assertOk()
                ->assertDontSee('id="leave-guard"', false);
        }
    }

    /** The edit screens are the same view, so they are guarded by the same code. */
    public function test_the_edit_screens_carry_it_too(): void
    {
        $supplier = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        /*
         * ⚠️ Two products, because a purchase whose units have been sold is
         * locked against editing — the fixture would then be testing the lock
         * rather than the guard.
         */
        $untouched = Product::create([
            'name' => 'Cable 2A', 'kind' => Product::KIND_STOCK, 'sku' => 'CX-2A',
            'barcode' => 'CX2A', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 2_000, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $untouched->id, 'quantity' => 10, 'unit_price' => 2_000]],
            user: $this->user(), purchaseDate: today(), amountPaid: 20_000,
        );

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->pd->id, 'quantity' => 10, 'unit_price' => 40_000]],
            user: $this->user(), purchaseDate: today(), amountPaid: 400_000,
        );

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today(), amountPaid: 120_000, paymentMethod: 'cash',
        );

        foreach ([route('sales.edit', $sale), route('purchases.edit', $purchase)] as $url) {
            $this->actingAs($this->user())->get($url)
                ->assertOk()
                ->assertSee('id="leave-guard"', false)
                ->assertSee('snapshot() !== pristine', false);
        }
    }
}
