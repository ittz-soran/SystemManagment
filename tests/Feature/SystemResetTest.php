<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SystemResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Start fresh" — the go-live tool that clears the testing period.
 *
 * The risk is obvious enough that most of this file is about the guards rather
 * than the deletion: Section 8b's "financial records are the shop's only proof
 * of who owes what" is exactly what this button destroys if it is ever pressed
 * on a real shop.
 */
class SystemResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        config(['backup.local' => storage_path('framework/testing/reset-'.uniqid()), 'backup.remote' => null]);

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Flash drives']);

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $this->customer = Customer::create(['name' => 'Karwan']);
    }

    protected function tearDown(): void
    {
        $directory = config('backup.local');

        if (is_string($directory) && is_dir($directory)) {
            foreach (glob($directory.'/*/*') ?: [] as $file) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function trade(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 50_000,
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 20_000,
        );

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );
    }

    public function test_it_clears_every_transaction_and_keeps_the_catalogue(): void
    {
        $this->trade();

        $this->assertGreaterThan(0, $this->product->refresh()->quantity);
        $this->assertGreaterThan(0, (int) $this->customer->refresh()->balance);

        app(SystemResetService::class)->run($this->admin);

        foreach ([
            'sales', 'sale_items', 'purchases', 'purchase_items', 'sale_returns', 'sale_return_items',
            'purchase_returns', 'purchase_return_items', 'payments', 'account_transactions',
            'stock_batches', 'stock_movements', 'stock_adjustments', 'expenses',
        ] as $table) {
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table($table)->count(), "{$table} should be empty after a reset");
        }

        // The catalogue that was entered carefully survives.
        $this->assertDatabaseHas('products', ['sku' => 'USB32']);
        $this->assertDatabaseHas('suppliers', ['name' => 'Bazaar Mobile']);
        $this->assertDatabaseHas('customers', ['name' => 'Karwan']);
        $this->assertDatabaseHas('categories', ['name' => 'Flash drives']);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
        $this->assertSame('Soran Store', setting('shop_name'));
    }

    /** Section 4: both are caches of tables that no longer have any rows. */
    public function test_stock_and_balances_go_back_to_zero(): void
    {
        $this->trade();

        app(SystemResetService::class)->run($this->admin);

        $this->assertSame(0, $this->product->refresh()->quantity);
        $this->assertSame(0, (int) $this->customer->refresh()->balance);
        $this->assertSame(0, (int) $this->supplier->refresh()->balance);
    }

    public function test_the_first_sale_afterwards_is_invoice_number_one(): void
    {
        $this->trade();

        app(SystemResetService::class)->run($this->admin);

        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 15_000,
        );

        $this->assertStringEndsWith('00001', $sale->document_no);
        $this->assertStringEndsWith('00001', Purchase::firstOrFail()->document_no);
    }

    /** Nothing is soft-deleted here — a hidden test sale is still a test sale. */
    public function test_nothing_is_left_behind_as_a_soft_delete(): void
    {
        $this->trade();

        app(SystemResetService::class)->run($this->admin);

        $this->assertSame(0, Sale::withTrashed()->count());
        $this->assertSame(0, Purchase::withTrashed()->count());
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_backup_is_taken_before_anything_is_removed(): void
    {
        $this->trade();

        $result = app(SystemResetService::class)->run($this->admin);

        $this->assertFileExists($result['backup']);

        // And it is a backup of the shop as it WAS: restoring it brings the
        // sales back, which is the whole reason for taking it.
        app(\App\Services\BackupService::class)->restore($result['backup']);

        $this->assertGreaterThan(0, Sale::count());
    }

    /** Frozen books mean the shop is really trading. That is not test data. */
    public function test_it_refuses_once_a_period_has_been_closed(): void
    {
        $this->trade();

        Setting::put('books_closed_before', today()->subMonth()->toDateString());
        Setting::flushCache();

        $this->assertNotNull(app(SystemResetService::class)->blocker());

        try {
            app(SystemResetService::class)->run($this->admin);
            $this->fail('A reset must be refused once any period has been frozen.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Books are closed', $e->getMessage());
        }

        $this->assertGreaterThan(0, Sale::count(), 'Nothing was removed');
    }

    public function test_the_preview_says_what_would_go(): void
    {
        $this->trade();

        $preview = app(SystemResetService::class)->preview();

        $this->assertSame(1, $preview['sales']);
        $this->assertSame(1, $preview['purchases']);
        $this->assertSame(1, $preview['sale_returns']);
        $this->assertArrayNotHasKey('products', $preview, 'The catalogue is not on the list');
    }

    // -------------------------------------------------------------- the screen

    public function test_the_wrong_confirmation_changes_nothing(): void
    {
        $this->trade();

        $this->actingAs($this->admin)
            ->from(route('settings.edit'))
            ->delete(route('settings.reset-transactions'), ['confirmation' => 'not the shop name'])
            ->assertSessionHasErrors('confirmation');

        $this->assertGreaterThan(0, Sale::count());
    }

    public function test_typing_the_shop_name_clears_it(): void
    {
        $this->trade();

        $this->actingAs($this->admin)
            ->delete(route('settings.reset-transactions'), ['confirmation' => setting('shop_name')])
            ->assertSessionHas('success');

        $this->assertSame(0, Sale::count());
    }

    /** The wipe is never silent, even though it clears the log it writes to. */
    public function test_the_reset_itself_is_recorded(): void
    {
        $this->trade();

        app(SystemResetService::class)->run($this->admin);

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'delete']);

        $this->assertStringContainsString(
            'Backup:',
            (string) \App\Models\ActivityLog::firstOrFail()->description,
        );
    }

    public function test_the_settings_page_shows_the_card_and_the_counts(): void
    {
        $this->trade();

        $this->actingAs($this->admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(__('Danger zone'))
            ->assertSee(__('Start fresh'))
            ->assertSee(__('Type :name to confirm', ['name' => 'Soran Store']));
    }

    public function test_a_closed_period_replaces_the_button_with_the_reason(): void
    {
        $this->trade();

        Setting::put('books_closed_before', today()->subMonth()->toDateString());
        Setting::flushCache();

        $this->actingAs($this->admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Books are closed', false)
            ->assertDontSee(__('Clear all transactions'));
    }

    public function test_only_someone_who_can_manage_settings_may_do_it(): void
    {
        $this->trade();

        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->delete(route('settings.reset-transactions'), ['confirmation' => setting('shop_name')])
            ->assertForbidden();

        $this->assertGreaterThan(0, Sale::count());
    }
}
