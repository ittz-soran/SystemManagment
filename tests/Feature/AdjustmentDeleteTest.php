<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Undoing a stock adjustment.
 *
 * The units go back exactly where they came from, read off the movements rather
 * than recomputed. Outgoing is always safe — putting units back on a shelf takes
 * nothing from anybody. Incoming is refused once anything has been sold out of
 * the batch it opened, because those units are on a customer's invoice now.
 */
class AdjustmentDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    private function buy(int $quantity = 10): void
    {
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile '.uniqid()]),
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    private function adjust(string $direction, int $quantity, ?int $cost = null): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(
            product: $this->product, direction: $direction, quantity: $quantity,
            reason: 'miscount', user: $this->admin, unitCost: $cost,
        );
    }

    public function test_undoing_an_outgoing_one_puts_the_units_back(): void
    {
        $this->buy(10);

        $adjustment = $this->adjust(StockAdjustment::DIRECTION_OUT, 3);
        $this->assertSame(7, $this->product->refresh()->quantity);

        app(StockAdjustmentService::class)->delete($adjustment, $this->admin);

        $this->assertSame(10, $this->product->refresh()->quantity);
        $this->assertSame(10, (int) StockBatch::where('product_id', $this->product->id)->sum('quantity_remaining'));
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->where('reference_id', $adjustment->id)->count());
        $this->assertSoftDeleted('stock_adjustments', ['id' => $adjustment->id]);
    }

    public function test_undoing_an_incoming_one_takes_its_batch_away(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        $this->assertSame(5, $this->product->refresh()->quantity);
        $this->assertSame(1, StockBatch::where('product_id', $this->product->id)->count());

        app(StockAdjustmentService::class)->delete($adjustment, $this->admin);

        $this->assertSame(0, $this->product->refresh()->quantity);
        $this->assertSame(0, StockBatch::where('product_id', $this->product->id)->count(),
            'The layer it opened goes with it, rather than lingering empty');
    }

    /**
     * The guard that matters. Those units are on somebody's invoice, costed at
     * 9,000 — taking the batch back would leave that sale costed against stock
     * that never existed.
     */
    public function test_it_refuses_once_the_units_it_added_have_been_sold(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $this->expectExceptionMessageMatches('/Cannot undo this/');

        try {
            app(StockAdjustmentService::class)->delete($adjustment, $this->admin);
        } finally {
            $this->assertSame(3, $this->product->refresh()->quantity, 'Nothing moved');
            $this->assertDatabaseHas('stock_adjustments', ['id' => $adjustment->id, 'deleted_at' => null]);
        }
    }

    public function test_a_closed_period_cannot_be_undone(): void
    {
        $this->buy(10);
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_OUT, 3);

        Setting::put('books_closed_before', today()->addDay()->toDateString());

        $this->actingAs($this->admin)
            ->from(route('stock-adjustments.show', $adjustment))
            ->delete(route('stock-adjustments.destroy', $adjustment))
            ->assertRedirect(route('stock-adjustments.show', $adjustment))
            ->assertSessionHas('error');

        $this->assertSame(7, $this->product->refresh()->quantity);
    }

    public function test_it_needs_the_permission(): void
    {
        $this->buy(10);
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_OUT, 3);

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['stock_adjustments.view'])->pluck('id'));

        $this->actingAs($clerk)
            ->delete(route('stock-adjustments.destroy', $adjustment))
            ->assertForbidden();

        $this->assertSame(7, $this->product->refresh()->quantity);
    }

    /** The product on an adjustment is a link to it, not just a name. */
    public function test_the_adjustment_links_to_its_product(): void
    {
        $this->buy(10);
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_OUT, 3);

        $this->actingAs($this->admin)
            ->get(route('stock-adjustments.show', $adjustment))->assertOk()
            ->assertSee('href="'.route('products.show', $this->product).'"', escape: false);
    }
}
