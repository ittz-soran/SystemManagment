<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SwapService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Section 8 — "A deleted document's lines outlived it".
 *
 * Soran, 2026-09-29, on PUR-00040: *"delete purchase and delete that items are
 * creates ASM and now i deleted ASM but not delete permanetly"*, with his
 * screen showing SQLSTATE[23000] and the failing foreign key.
 *
 * A return was made against the purchase and deleted. That reversed everything
 * that mattered and soft-deleted the return — but left its lines, which point
 * at `purchase_items` with `restrictOnDelete`. Deleting the purchase then hit
 * the foreign key, and rule 3 could not see the deleted return to stop it.
 */
class DeletedReturnLinesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->supplier = Supplier::create(['name' => 'Click Gaming']);

        $this->product = Product::create([
            'name' => 'Power Supply 650W 80+ Acer', 'sku' => 'SS112',
            'category_id' => Category::firstOrCreate(['name' => 'Parts'])->id,
            'unit' => 'pcs', 'purchase_price' => 62_800, 'sale_price' => 80_000, 'quantity' => 0,
        ]);
    }

    private function buy(int $quantity = 4): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 62_800]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    // ---- his case, exactly ------------------------------------------------

    public function test_a_purchase_can_be_deleted_after_its_return_was_deleted(): void
    {
        $purchase = $this->buy();
        $item = $purchase->items()->firstOrFail();

        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $item->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        // ⚠️ The lines go with the return, or the foreign key below refuses.
        // ⚠️ The lines go with the return. Without this the delete below dies
        // on the foreign key — his exact error, same SQL:
        // FOREIGN KEY constraint failed … delete from purchase_items where purchase_id = …
        $this->assertSame(0, DB::table('purchase_return_items')
            ->where('purchase_return_id', $return->id)->count());

        app(PurchaseService::class)->delete($purchase->fresh(), $this->admin);

        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);
        $this->assertSame(0, DB::table('purchase_items')->where('purchase_id', $purchase->id)->count());
    }

    /** The header stays: an audit of deleted documents reads its number. */
    public function test_the_deleted_return_keeps_its_number(): void
    {
        $purchase = $this->buy();
        $item = $purchase->items()->firstOrFail();

        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $item->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );
        $number = $return->document_no;

        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $this->assertSame($number, PurchaseReturn::withTrashed()->findOrFail($return->id)->document_no);
    }

    /** ⚠️ A LIVE return keeps every line it has, and still blocks the purchase. */
    public function test_a_live_return_keeps_its_lines_and_still_locks_the_purchase(): void
    {
        $purchase = $this->buy();
        $item = $purchase->items()->firstOrFail();

        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $item->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $this->assertSame(1, DB::table('purchase_return_items')
            ->where('purchase_return_id', $return->id)->count());

        // Locked either way: the returned unit has left the batch, so rule 2
        // refuses before rule 3 is even reached. What matters here is that the
        // lines are still there and the purchase cannot go.
        $this->assertFalse($purchase->fresh()->canBeDeleted($this->admin)['allowed']);
    }

    // ---- the same fault on the sale side ----------------------------------

    public function test_a_sale_can_be_deleted_after_its_return_was_deleted(): void
    {
        $this->buy(10);

        $sale = app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 80_000]],
            user: $this->admin, saleDate: now(), amountPaid: 160_000,
        );

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        app(SaleReturnService::class)->delete($return, $this->admin);

        $this->assertSame(0, DB::table('sale_return_items')
            ->where('sale_return_id', $return->id)->count());
        $this->assertSame($return->document_no, SaleReturn::withTrashed()->findOrFail($return->id)->document_no);

        app(SaleService::class)->delete($sale->fresh(), $this->admin);

        $this->assertSoftDeleted('sales', ['id' => $sale->id]);
        $this->assertSame(0, DB::table('sale_items')->where('sale_id', $sale->id)->count());
    }

    /**
     * ⚠️ **A swap IS the line reference**, with no lines of its own:
     * `swaps.sale_item_id` is `restrictOnDelete` and `Swap` soft-deletes, so a
     * swap the shop has already deleted refuses its sale's delete in exactly
     * the same way — the same fault in its third shape.
     */
    public function test_a_sale_can_be_deleted_after_its_swap_was_deleted(): void
    {
        $this->buy(10);

        $sale = app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 80_000]],
            user: $this->admin, saleDate: now(), amountPaid: 160_000,
        );

        $swap = app(SwapService::class)->create(
            saleItem: $sale->items()->firstOrFail(),
            quantity: 1,
            user: $this->admin,
        );

        app(SwapService::class)->delete($swap, $this->admin);

        $this->assertSoftDeleted('swaps', ['id' => $swap->id]);

        app(SaleService::class)->delete($sale->fresh(), $this->admin);

        $this->assertSoftDeleted('sales', ['id' => $sale->id]);
        $this->assertSame(0, DB::table('sale_items')->where('sale_id', $sale->id)->count());

        // The tombstone went with the sale it was against: it points at a line
        // that no longer exists, and nothing can read it.
        $this->assertSame(0, DB::table('swaps')->where('id', $swap->id)->count());
    }

    // ---- what the shopkeeper is shown -------------------------------------

    /**
     * ⚠️ **THE DATABASE'S OWN WORDS ARE NEVER PUT IN FRONT OF A SHOPKEEPER.**
     * `QueryException` extends `PDOException` extends `RuntimeException`, which
     * is why `catch (RuntimeException)` printed the SQL, the constraint, the
     * host and the database name on his screen.
     */
    public function test_a_database_refusal_is_one_plain_sentence(): void
    {
        $raw = new QueryException(
            'mysql',
            'delete from `purchase_items` where `purchase_id` = 48',
            [],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row'),
        );

        $shown = problem($raw);

        $this->assertStringNotContainsString('SQLSTATE', $shown);
        $this->assertStringNotContainsString('purchase_items', $shown);
        $this->assertStringNotContainsString('mysql', $shown);
        $this->assertStringContainsString('error log', $shown);
    }

    /** The sentences the shop writes for itself come through untouched. */
    public function test_the_shop_s_own_wording_is_not_replaced(): void
    {
        $this->assertSame(
            'Locked: more than 24 hours old.',
            problem(new \RuntimeException('Locked: more than 24 hours old.')),
        );
    }
}
