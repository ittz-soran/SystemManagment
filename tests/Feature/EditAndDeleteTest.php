<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Section 8: "An edit is a reversal followed by a re-application, in one
 * transaction." The lock rules are what make that safe, so this test covers
 * both halves — that an unlocked edit lands exactly, and that a locked one is
 * refused rather than quietly corrupting FIFO order.
 */
class EditAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Product $other;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 30_000, 'quantity' => 0,
        ]);

        $this->other = Product::create([
            'name' => 'Gadget', 'sku' => 'G1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 4_000, 'sale_price' => 9_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'S']);
        $this->customer = Customer::create(['name' => 'C']);
    }

    // ---------------------------------------------------------------- sales

    public function test_editing_a_sale_reverses_it_and_re_applies_the_new_lines(): void
    {
        $batch = $this->buy($this->product, 10, 10_000);
        $sale = $this->sell($this->product, 3, 30_000);

        $this->assertSame(7, $batch->refresh()->quantity_remaining);
        $this->assertSame(90_000, (int) $this->customer->refresh()->balance);

        app(SaleService::class)->update(
            sale: $sale,
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 25_000]],
            user: $this->user,
            saleDate: now(),
        );

        // The batch was restored to 10 and then consumed again down to 5, not
        // adjusted by the difference.
        $this->assertSame(5, $batch->refresh()->quantity_remaining);
        $this->assertSame(5, $this->product->refresh()->quantity);
        $this->assertSame(125_000, (int) $sale->refresh()->total_amount);
        $this->assertSame(125_000, (int) $this->customer->refresh()->balance);

        // The old movements are gone, so the trail does not double-count. Stock
        // going out is recorded negative, so five units read as -5.
        $this->assertSame(-5, (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)->sum('quantity'));
    }

    public function test_an_edit_keeps_the_payment_and_only_re_posts_what_is_still_owed(): void
    {
        $this->buy($this->product, 10, 10_000);

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 50_000,
        );

        $this->assertSame(40_000, (int) $this->customer->refresh()->balance);

        app(SaleService::class)->update(
            sale: $sale,
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(),
        );

        // Section 8: payments are untouched by an edit.
        $this->assertSame(50_000, $sale->refresh()->amountPaid());
        $this->assertSame(70_000, (int) $this->customer->refresh()->balance);
        $this->assertSame(70_000, $sale->amountDue());
    }

    /** Section 8 rule 4: an edit may not leave the customer owed money. */
    public function test_an_edit_below_what_is_already_paid_is_refused(): void
    {
        $this->buy($this->product, 10, 10_000);

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 90_000,
        );

        try {
            app(SaleService::class)->update(
                sale: $sale,
                customer: $this->customer,
                lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 30_000]],
                user: $this->user, saleDate: now(),
            );
            $this->fail('An edit below the amount already paid must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already paid', $e->getMessage());
        }

        $this->assertSame(90_000, (int) $sale->refresh()->total_amount);
        $this->assertSame(3, $sale->items()->sum('quantity'));
    }

    /**
     * Lock rule 3, and the reason the whole edit flow is safe: a later movement
     * on the same product means re-running FIFO would take stock a later
     * document is already holding.
     */
    public function test_a_sale_locks_once_stock_for_its_product_has_moved_again(): void
    {
        $this->buy($this->product, 10, 10_000);
        $sale = $this->sell($this->product, 3, 30_000);

        // A second sale takes more of the same batch.
        $this->travel(1)->seconds();
        $this->sell($this->product, 2, 30_000);

        $lock = $sale->refresh()->canBeModified($this->user);

        $this->assertFalse($lock['allowed']);
        $this->assertStringContainsString('invert FIFO order', $lock['reason']);

        $this->expectExceptionMessageMatches('/invert FIFO order/');
        app(SaleService::class)->update(
            sale: $sale, customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(),
        );
    }

    public function test_deleting_a_sale_puts_its_units_back_in_the_batches_they_came_from(): void
    {
        // Two batches at different costs, so a wrong restore is visible.
        $first = $this->buy($this->product, 4, 10_000);
        $this->travel(1)->seconds();
        $second = $this->buy($this->product, 6, 12_000);

        $this->travel(1)->seconds();
        $sale = $this->sell($this->product, 6, 30_000);

        $this->assertSame(0, $first->refresh()->quantity_remaining);
        $this->assertSame(4, $second->refresh()->quantity_remaining);

        app(SaleService::class)->delete($sale, $this->user);

        $this->assertSame(4, $first->refresh()->quantity_remaining, 'The cheap layer is whole again');
        $this->assertSame(6, $second->refresh()->quantity_remaining);
        $this->assertSame(10, $this->product->refresh()->quantity);
        $this->assertSame(0, (int) $this->customer->refresh()->balance);
        $this->assertSoftDeleted('sales', ['id' => $sale->id]);
    }

    public function test_deleting_a_sale_returns_the_money_the_customer_owed(): void
    {
        $this->buy($this->product, 10, 10_000);

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 20_000,
        );

        $this->assertSame(40_000, (int) $this->customer->refresh()->balance);

        app(SaleService::class)->delete($sale, $this->user);

        $this->assertSame(0, (int) $this->customer->refresh()->balance);
    }

    // ------------------------------------------------------------ purchases

    public function test_editing_a_purchase_replaces_its_batches(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->assertSame(5, $this->product->refresh()->quantity);
        $this->assertSame(50_000, (int) $this->supplier->refresh()->balance);

        app(PurchaseService::class)->update(
            purchase: $purchase,
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 8, 'unit_price' => 11_000]],
            user: $this->user, purchaseDate: now(),
        );

        $batches = StockBatch::where('source_type', StockBatch::SOURCE_PURCHASE)
            ->where('source_id', $purchase->id)->get();

        $this->assertCount(1, $batches, 'The old batch is replaced, not added to');
        $this->assertSame(8, (int) $batches->first()->quantity_in);
        // Rule 2: the batch cost is the unit price typed by the user.
        $this->assertSame(11_000, (int) $batches->first()->unit_cost);
        $this->assertSame(8, $this->product->refresh()->quantity);
        $this->assertSame(88_000, (int) $this->supplier->refresh()->balance);
    }

    /** Lock rule 2: every batch must still be untouched. */
    public function test_a_purchase_locks_once_a_unit_from_it_has_been_sold(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->sell($this->product, 1, 30_000);

        $lock = $purchase->refresh()->canBeModified($this->user);

        $this->assertFalse($lock['allowed']);
        $this->assertStringContainsString('already been used', $lock['reason']);
    }

    /**
     * Section 8: delete is stricter than edit. A purchase whose stock has since
     * been returned to the supplier has no units missing, but the movement rows
     * would be orphaned, so it may be corrected and not removed.
     */
    public function test_a_purchase_used_in_a_sale_can_be_edited_but_not_deleted(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        // Sell one and take it back as a return, so every unit is on the shelf
        // again — but the movements that touched the batch remain, which is
        // exactly the "since cancelled by a return" case the doc names.
        $sale = $this->sell($this->product, 1, 30_000);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(5, $this->product->refresh()->quantity);
        $this->assertTrue($purchase->refresh()->canBeModified($this->user)['allowed']);

        $delete = $purchase->canBeDeleted($this->user);

        $this->assertFalse($delete['allowed']);
        $this->assertStringContainsString('used in a sale', $delete['reason']);
    }

    public function test_deleting_a_purchase_removes_its_stock_and_what_was_owed(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        app(PurchaseService::class)->delete($purchase, $this->user);

        $this->assertSame(0, $this->product->refresh()->quantity);
        $this->assertSame(0, (int) $this->supplier->refresh()->balance);
        $this->assertSame(0, StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)->count());
        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);
    }

    // ---------------------------------------------------------- the screens

    public function test_the_sale_page_offers_edit_and_delete_and_the_edit_screen_is_prefilled(): void
    {
        $this->buy($this->product, 10, 10_000);
        $sale = $this->sell($this->product, 3, 30_000);

        $this->actingAs($this->user)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee(route('sales.edit', $sale))
            ->assertSee(route('sales.destroy', $sale));

        $this->actingAs($this->user)
            ->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertSee($sale->document_no)
            // The cart is preloaded with the sale's own lines.
            ->assertSee('"quantity":3', false)
            ->assertSee('"price":30000', false);
    }

    /** Section 9b: never hide them — disable them and say why. */
    public function test_a_locked_sale_still_shows_the_buttons_with_the_reason(): void
    {
        $this->buy($this->product, 10, 10_000);
        $sale = $this->sell($this->product, 3, 30_000);

        $this->travel(1)->seconds();
        $this->sell($this->product, 1, 30_000);

        $this->actingAs($this->user)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('disabled', false)
            ->assertSee('invert FIFO order', false)
            ->assertDontSee(route('sales.edit', $sale));
    }

    public function test_the_purchase_edit_screen_is_prefilled(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->actingAs($this->user)
            ->get(route('purchases.edit', $purchase))
            ->assertOk()
            ->assertSee($purchase->document_no)
            ->assertSee('"quantity":5', false)
            ->assertSee('"price":10000', false);
    }

    public function test_the_edit_screen_redirects_when_the_record_is_locked(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->sell($this->product, 1, 30_000);

        $this->actingAs($this->user)
            ->get(route('purchases.edit', $purchase))
            ->assertRedirect(route('purchases.show', $purchase))
            ->assertSessionHas('error');
    }

    // ---------------------------------------------------------- bulk delete

    /**
     * Section 8b: "12 deleted, 3 skipped: already used in sales." A refusal is a
     * result, not a failure — the rows that could go still go.
     */
    public function test_bulk_delete_skips_locked_rows_and_keeps_the_rest(): void
    {
        $this->buy($this->product, 20, 10_000);

        // Three sales of a product nothing else touches, so each is unlocked.
        $this->buy($this->other, 30, 4_000);

        $a = $this->sell($this->other, 1, 9_000);
        $b = $this->sell($this->other, 2, 9_000);
        $c = $this->sell($this->other, 3, 9_000);

        // A fourth sale of a different product, locked by a later movement.
        $locked = $this->sell($this->product, 1, 30_000);
        $this->travel(1)->seconds();
        $this->sell($this->product, 1, 30_000);

        $this->assertFalse($locked->refresh()->canBeModified($this->user)['allowed']);

        $response = $this->actingAs($this->user)->delete(route('sales.bulk-destroy'), [
            'ids' => [$a->id, $b->id, $c->id, $locked->id],
        ]);

        $response->assertSessionHas('success');

        $message = session('success');
        $this->assertStringContainsString('3 deleted', $message);
        $this->assertStringContainsString('1 skipped', $message);
        $this->assertStringContainsString('invert FIFO order', $message);

        // The three good ones went; the locked one and its stock did not move.
        foreach ([$a, $b, $c] as $sale) {
            $this->assertSoftDeleted('sales', ['id' => $sale->id]);
        }

        $this->assertDatabaseHas('sales', ['id' => $locked->id, 'deleted_at' => null]);
        $this->assertSame(30, $this->other->refresh()->quantity);
        $this->assertSame(18, $this->product->refresh()->quantity);
    }

    /** With nothing deletable the batch reports it, and nothing is lost. */
    public function test_bulk_delete_reports_when_every_row_was_locked(): void
    {
        $this->buy($this->product, 10, 10_000);

        $locked = $this->sell($this->product, 1, 30_000);
        $this->travel(1)->seconds();
        $this->sell($this->product, 1, 30_000);

        $this->actingAs($this->user)
            ->delete(route('sales.bulk-destroy'), ['ids' => [$locked->id]])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('sales', ['id' => $locked->id, 'deleted_at' => null]);
        $this->assertSame(2, Sale::count());
    }

    public function test_bulk_delete_of_purchases_uses_the_stricter_delete_rule(): void
    {
        $keepable = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->travel(1)->seconds();

        $used = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->other->id, 'quantity' => 5, 'unit_price' => 4_000]],
            user: $this->user, purchaseDate: now(),
        );

        // Sell from the second one and take it back as a return, so its stock is
        // whole again and only the stricter delete rule bites.
        $this->travel(1)->seconds();
        $sale = $this->sell($this->other, 1, 9_000);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->user, returnDate: now(),
        );

        $this->actingAs($this->user)->delete(route('purchases.bulk-destroy'), [
            'ids' => [$keepable->id, $used->id],
        ])->assertSessionHas('success');

        $this->assertStringContainsString('used in a sale', session('success'));
        $this->assertSoftDeleted('purchases', ['id' => $keepable->id]);
        $this->assertDatabaseHas('purchases', ['id' => $used->id, 'deleted_at' => null]);
        $this->assertSame(0, $this->product->refresh()->quantity);
        $this->assertSame(5, $this->other->refresh()->quantity);
    }

    // ------------------------------------------------------------- helpers

    private function buy(Product $product, int $quantity, int $unitPrice): StockBatch
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            user: $this->user, purchaseDate: now(),
        );

        return StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function sell(Product $product, int $quantity, int $unitPrice): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            user: $this->user, saleDate: now(), amountPaid: 0,
        );
    }
}
