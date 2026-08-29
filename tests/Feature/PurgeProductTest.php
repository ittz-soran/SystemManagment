<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a product for good.
 *
 * Everything else the system calls "delete" is a soft delete and can be undone
 * from the same screen. This is the row leaving the table, and with it the SKU
 * and the barcode that were usually the reason anybody wanted it gone.
 *
 * Which is fine for the thing typed in by mistake and never used, and is
 * unthinkable for anything on an invoice — so the whole of this is about the
 * line between the two being drawn before the button rather than discovered
 * afterwards as an integrity-constraint error page.
 */
class PurgeProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        // Signed in from the start: the observer declines to write an
        // unattributable row, so a product built by a test with nobody logged
        // in has no history for the purge to clear.
        $this->actingAs($this->admin);

        $this->category = Category::create(['name' => 'Accessories']);
    }

    private function product(string $name, string $sku, ?string $barcode = null): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'barcode' => $barcode,
            'category_id' => $this->category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);
    }

    public function test_a_product_nothing_ever_touched_is_destroyed_outright(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1', '5901234123457');
        $typo->delete();

        $this->actingAs($this->admin)
            ->delete(route('products.purge', $typo))
            ->assertRedirect(route('products.index', ['deleted' => 1]));

        $this->assertDatabaseMissing('products', ['id' => $typo->id]);
    }

    /** The whole point: the codes it was holding are usable again. */
    public function test_the_sku_and_barcode_are_free_afterwards(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1', '5901234123457');
        $typo->delete();

        $this->actingAs($this->admin)->delete(route('products.purge', $typo));

        $again = $this->product('Typed properly this time', 'OOPS1', '5901234123457');

        $this->assertTrue($again->exists);
    }

    public function test_a_product_on_an_invoice_is_refused_and_stays(): void
    {
        $sold = $this->product('Really sold', 'SOLD1');

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $sold->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [['product_id' => $sold->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 30_000,
        );

        // It would normally be deactivated rather than deleted; forced into the
        // deleted list so the destroy path is what is being tested.
        $sold->delete();

        $this->actingAs($this->admin)
            ->delete(route('products.purge', $sold))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $sold->id]);
    }

    /** The refusal names what is holding it, in documents rather than in tables. */
    public function test_the_refusal_says_what_is_holding_it(): void
    {
        $sold = $this->product('Really sold', 'SOLD1');

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $sold->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [['product_id' => $sold->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 30_000,
        );

        $reason = $sold->canBePurged()['reason'];

        $this->assertStringContainsString('1 sale', $reason);
        $this->assertStringContainsString('1 purchase', $reason);
    }

    /**
     * The check has to ask the question the foreign key asks.
     *
     * stock_adjustments is soft-deleted AND carries the archived-period scope,
     * so counting it through Eloquent would report nothing while MySQL still
     * refuses the delete. That gap is the difference between a sentence and a
     * 500.
     */
    public function test_a_hidden_adjustment_still_holds_the_product(): void
    {
        $product = $this->product('Adjusted once', 'ADJ1');

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $adjustment = app(StockAdjustmentService::class)->create(
            product: $product->refresh(), direction: StockAdjustment::DIRECTION_OUT,
            quantity: 1, reason: 'damage', user: $this->admin,
        );

        // Out of sight of every screen, and still in the table.
        $adjustment->delete();

        $this->assertFalse($product->canBePurged()['allowed']);

        $product->delete();

        $this->actingAs($this->admin)
            ->delete(route('products.purge', $product))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /** Nothing goes from the shelf to gone in one press. */
    public function test_a_product_that_is_not_deleted_cannot_be_destroyed(): void
    {
        $onTheShelf = $this->product('Still stocked', 'HERE1');

        $this->actingAs($this->admin)
            ->delete(route('products.purge', $onTheShelf))
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $onTheShelf->id]);
    }

    /** products.delete is enough to hide a product. It is not enough to destroy one. */
    public function test_staff_holding_every_product_permission_still_cannot_destroy(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1');
        $typo->delete();

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'like', 'products.%')->pluck('id')->all());

        $this->actingAs($staff)
            ->delete(route('products.purge', $typo))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $typo->id]);
    }

    /** A backup is taken before the row goes, not after. */
    public function test_a_backup_is_written_first(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1');
        $typo->delete();

        $this->actingAs($this->admin)->delete(route('products.purge', $typo));

        $backup = ActivityLog::where('module', 'settings')
            ->where('description', 'like', 'Backed up%')->latest('id')->first();

        $purge = ActivityLog::where('action', 'purge')->latest('id')->first();

        $this->assertNotNull($backup, 'the backup ran');
        $this->assertNotNull($purge);

        // Before, not after: the file has to exist while the row still does.
        $this->assertLessThan($purge->id, $backup->id);
    }

    /**
     * Its own history goes with it — those rows would point at nothing — but
     * the fact that somebody destroyed it never does.
     */
    public function test_the_history_goes_and_the_destruction_is_recorded(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1');
        $typo->delete();

        $this->assertGreaterThan(0, ActivityLog::where('module', 'products')
            ->where('record_id', $typo->id)->count());

        $this->actingAs($this->admin)->delete(route('products.purge', $typo));

        $this->assertSame(0, ActivityLog::where('module', 'products')
            ->where('record_id', $typo->id)->count(), 'no orphan history left behind');

        $recorded = ActivityLog::where('action', 'purge')->latest('id')->first();

        $this->assertNotNull($recorded);
        $this->assertSame($this->admin->id, $recorded->user_id);
        $this->assertStringContainsString('Typed by mistake', $recorded->description);
        $this->assertStringContainsString('OOPS1', $recorded->description);
    }

    /** The button is on the screen, and disabled with its reason when it cannot be pressed. */
    public function test_the_deleted_list_shows_the_button_and_the_reason(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1');
        $typo->delete();

        $sold = $this->product('Really sold', 'SOLD1');
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $sold->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
        $sold->delete();

        $page = $this->actingAs($this->admin)->get(route('products.index', ['deleted' => 1]));

        $page->assertOk()
            ->assertSee(__('Delete permanently'), false)
            ->assertSee('disabled', false)
            ->assertSee('1 purchase', false);
    }

    /** Staff never see a button they may not press. */
    public function test_the_button_is_not_on_the_page_for_staff(): void
    {
        $typo = $this->product('Typed by mistake', 'OOPS1');
        $typo->delete();

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant2@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'like', 'products.%')->pluck('id')->all());

        $this->actingAs($staff)->get(route('products.index', ['deleted' => 1]))
            ->assertOk()
            ->assertDontSee(__('Delete permanently'), false);
    }

    /** The list asks its seven questions once for the page, not once per row. */
    public function test_the_deleted_list_does_not_query_per_row(): void
    {
        foreach (range(1, 12) as $n) {
            $this->product("Spare {$n}", "SP{$n}")->delete();
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('products.index', ['deleted' => 1]))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $queries, "the deleted list ran {$queries} queries");
    }
}
