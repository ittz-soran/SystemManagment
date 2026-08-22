<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Undoing a return to a supplier.
 *
 * The mirror image of undoing a sale return, and easier for a reason worth
 * pinning down: a sale return puts units back on the shelf, so undoing it has
 * to take them away again and somebody may already have bought them. A purchase
 * return sends units away, so undoing it puts them back into the batch they
 * left — which nobody else can have touched.
 *
 * What is left to guard is the period, the permission, and the arithmetic.
 */
class PurchaseReturnDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

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

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $this->customer = Customer::create(['name' => 'Karwan']);
    }

    private function buy(int $quantity = 10, int $paid = 0): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: $paid,
        );
    }

    private function returnToSupplier(Purchase $purchase, int $quantity = 3): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => $quantity]],
            user: $this->admin, returnDate: now(),
        );
    }

    // -------------------------------------------------------------- the undo

    public function test_the_goods_go_back_into_the_batch_they_left(): void
    {
        $purchase = $this->buy(10);
        $batch = StockBatch::where('source_id', $purchase->id)->firstOrFail();

        $return = $this->returnToSupplier($purchase, 3);

        $this->assertSame(7, $batch->refresh()->quantity_remaining);
        $this->assertSame(7, $this->product->refresh()->quantity);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame(10, $batch->refresh()->quantity_remaining, 'Back to its pre-return state exactly');
        $this->assertSame(10, (int) $batch->quantity_in, 'And never more than was bought');
        $this->assertSame(10, $this->product->refresh()->quantity);

        // The movements are gone, so the trail does not double-count.
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_PURCHASE_RETURN)
            ->where('reference_id', $return->id)->count());
    }

    public function test_the_line_can_be_returned_again_afterwards(): void
    {
        $purchase = $this->buy(10);
        $item = $purchase->items()->firstOrFail();

        $return = $this->returnToSupplier($purchase, 4);
        $this->assertSame(4, $item->refresh()->quantity_returned);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame(0, $item->refresh()->quantity_returned);
        $this->assertSame(10, $item->returnableQuantity());
    }

    public function test_the_supplier_is_owed_again(): void
    {
        $purchase = $this->buy(10);

        $this->assertSame(100_000, (int) $this->supplier->refresh()->balance);

        $return = $this->returnToSupplier($purchase, 3);

        $this->assertSame(70_000, (int) $this->supplier->refresh()->balance);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame(100_000, (int) $this->supplier->refresh()->balance);
    }

    /**
     * Section 4: a return bigger than what is still owed clears the balance and
     * the rest comes back as cash. Undoing it sends that cash out again.
     */
    public function test_cash_the_supplier_handed_back_goes_out_again(): void
    {
        $purchase = $this->buy(10, paid: 100_000);

        $this->assertSame(0, (int) $this->supplier->refresh()->balance);

        $return = $this->returnToSupplier($purchase, 3);

        // Nothing was owed, so all 30,000 came back as cash.
        $this->assertSame(0, (int) $this->supplier->refresh()->balance);
        $this->assertSame(30_000, (int) $return->payments()->sum('amount'));

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame(0, $return->payments()->count(), 'The inbound payment is reversed');
        $this->assertSame(0, (int) $this->supplier->refresh()->balance);
    }

    public function test_the_purchase_goes_back_to_active(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 10);

        $this->assertSame('returned', $purchase->refresh()->status);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame('active', $purchase->refresh()->status);
    }

    public function test_it_is_soft_deleted_and_recorded(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 3);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSoftDeleted('purchase_returns', ['id' => $return->id]);

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'purchase_returns',
            'action' => 'delete',
        ]);
    }

    // ----------------------------------------------------------- the conditions

    /** Section 8: nothing dated before books_closed_before can be deleted. */
    public function test_a_closed_period_refuses_it(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 3);

        Setting::put('books_closed_before', today()->addDay()->toDateString());
        Setting::flushCache();

        $state = $return->canBeDeleted($this->admin);

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('closed period', $state['reason']);

        try {
            app(PurchaseReturnService::class)->delete($return, $this->admin);
            $this->fail('A return in a closed period must not be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('closed period', $e->getMessage());
        }

        $this->assertSame(1, PurchaseReturn::count(), 'Nothing was removed');
        $this->assertSame(7, $this->product->refresh()->quantity, 'And no stock moved');
    }

    public function test_it_needs_the_permission(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 3);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->permissions()->sync(
            Permission::whereIn('key', ['purchase_returns.view'])->pluck('id')
        );

        $state = $return->canBeDeleted($user);

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('permission', $state['reason']);

        $this->actingAs($user)
            ->delete(route('purchase-returns.destroy', $return))
            ->assertForbidden();

        $this->assertSame(1, PurchaseReturn::count());
    }

    /**
     * The arithmetic guard. Nothing in the system should be able to breach it,
     * which is exactly why it is checked before writing rather than discovered
     * afterwards — a batch holding more than was bought would make every FIFO
     * figure downstream of it wrong.
     */
    public function test_it_refuses_rather_than_let_a_batch_hold_more_than_was_bought(): void
    {
        $purchase = $this->buy(10);
        $batch = StockBatch::where('source_id', $purchase->id)->firstOrFail();
        $return = $this->returnToSupplier($purchase, 3);

        $this->assertSame(7, $batch->refresh()->quantity_remaining);

        // Something outside the normal flow has refilled the batch.
        $batch->forceFill(['quantity_remaining' => 10])->save();

        $state = $return->canBeDeleted($this->admin);

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('more than was bought', $state['reason']);

        $this->expectExceptionMessageMatches('/more than was bought/');
        app(PurchaseReturnService::class)->delete($return, $this->admin);
    }

    /**
     * The condition that does NOT apply here, and the reason the whole thing is
     * simpler than undoing a sale return: goods sold after the return came from
     * elsewhere, so putting these back cannot clash with anybody.
     */
    public function test_selling_other_stock_afterwards_does_not_block_it(): void
    {
        $first = $this->buy(10);
        $batch = StockBatch::where('source_id', $first->id)->firstOrFail();

        $return = $this->returnToSupplier($first, 3);

        // A second purchase, and a sale that eats into both batches.
        $this->travel(1)->seconds();
        $this->buy(5);

        $this->travel(1)->seconds();
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 9, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $this->assertSame(0, $batch->refresh()->quantity_remaining, 'The first batch is empty');
        $this->assertSame(3, $this->product->refresh()->quantity);

        // Undoing the return still works: those 3 units belong to this batch and
        // were never anyone else's to take.
        $this->assertTrue($return->refresh()->canBeDeleted($this->admin)['allowed']);

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame(3, $batch->refresh()->quantity_remaining);
        $this->assertSame(6, $this->product->refresh()->quantity);
    }

    // -------------------------------------------------------------- the screen

    public function test_the_page_offers_the_button_and_deleting_works_through_it(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 3);

        // The destroy URL is the same path as the show one, so the form itself
        // is what tells them apart.
        $this->actingAs($this->admin)
            ->get(route('purchase-returns.show', $return))
            ->assertOk()
            ->assertSee(__('Delete return'))
            ->assertSee('name="_method" value="DELETE"', false);

        $this->actingAs($this->admin)
            ->delete(route('purchase-returns.destroy', $return))
            ->assertRedirect(route('purchases.show', $purchase->id))
            ->assertSessionHas('success');

        $this->assertSame(10, $this->product->refresh()->quantity);
    }

    /** Section 9b: never hidden — disabled, with the reason as its tooltip. */
    public function test_a_blocked_button_is_shown_disabled_with_the_reason(): void
    {
        $purchase = $this->buy(10);
        $return = $this->returnToSupplier($purchase, 3);

        Setting::put('books_closed_before', today()->addDay()->toDateString());
        Setting::flushCache();

        $this->actingAs($this->admin)
            ->get(route('purchase-returns.show', $return))
            ->assertOk()
            ->assertSee(__('Delete return'))
            ->assertSee('disabled', false)
            ->assertSee('closed period', false)
            ->assertDontSee('name="_method" value="DELETE"', false);
    }
}
