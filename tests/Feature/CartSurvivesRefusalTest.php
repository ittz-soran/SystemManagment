<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A refused sale must not throw the basket away.
 *
 * **Found by Soran at a real till, 2026-09-12.** He rang up a sale for the Cash
 * Customer without paying it in full — Section 4 forbids that, correctly — and
 * the screen came back with the message and an EMPTY CART. Every line had to be
 * scanned again to correct one field.
 *
 * The lines were never actually lost: `store()` has always sent them back with
 * `withInput()`. Nothing read them. The cart is seeded from `$cartLines`, which
 * was filled in only when resuming a held cart, so a failed submit rebuilt the
 * page from nothing while the data sat unused in the session.
 *
 * That is the shape of it worth remembering: not a missing feature, but two
 * halves that were each doing their job and had never been introduced.
 */
class CartSurvivesRefusalTest extends TestCase
{
    use RefreshDatabase;

    private function stocked(User $admin, string $sku = 'K1'): Product
    {
        $product = Product::create([
            'name' => 'Charger cable', 'sku' => $sku,
            'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 500, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S'.$sku]),
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 500]],
            user: $admin, purchaseDate: now(),
        );

        return $product;
    }

    /** ⚠️ Soran's exact case: the Cash Customer, not paid in full. */
    public function test_a_cash_customer_underpayment_keeps_the_basket(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $product = $this->stocked($admin);

        $this->actingAs($admin)
            ->from(route('sales.create'))
            ->post(route('sales.store'), [
                'customer_id' => Customer::cashCustomer()->id,
                'sale_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'amount_paid' => 100,           // nowhere near the 4,000 owed
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 2_000],
                ],
            ])
            ->assertRedirect(route('sales.create'))
            ->assertSessionHas('error');

        // Following the redirect: the basket is still on the screen.
        $this->actingAs($admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('Charger cable')
            ->assertSee('"quantity":2', false)
            ->assertSee('"price":2000', false);
    }

    /** And a line refused for stock, which is the other everyday refusal. */
    public function test_a_sale_refused_for_stock_keeps_the_basket(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $product = $this->stocked($admin, 'K2');

        $this->actingAs($admin)
            ->from(route('sales.create'))
            ->post(route('sales.store'), [
                'customer_id' => Customer::create(['name' => 'On account'])->id,
                'sale_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'amount_paid' => 0,
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 999, 'unit_price' => 2_000],
                ],
            ])
            ->assertRedirect(route('sales.create'));

        $this->actingAs($admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('"quantity":999', false);
    }

    /** Purchases have the same cart and had the same hole. */
    public function test_a_refused_purchase_keeps_the_basket(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $product = $this->stocked($admin, 'K3');

        $this->actingAs($admin)
            ->from(route('purchases.create'))
            ->post(route('purchases.store'), [
                'supplier_id' => 999999,     // refused: no such supplier
                'purchase_date' => today()->toDateString(),
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 600],
                ],
            ])
            ->assertRedirect(route('purchases.create'));

        $this->actingAs($admin)
            ->get(route('purchases.create'))
            ->assertOk()
            ->assertSee('"quantity":3', false);
    }

    /** A fresh screen is still an empty cart — old input must not leak forward. */
    public function test_a_fresh_sale_screen_is_empty(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('const cart = []', false);
    }
}
