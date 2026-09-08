<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * The report must reproduce the Section 10b profit table exactly:
     * revenue 60,000 · gross profit 40,000 · discounts 4,000 · write-off 10,000
     * · net 34,000.
     *
     * The acceptance test proves the underlying data; this proves the report
     * reads it the same way.
     */
    public function test_the_profit_report_reproduces_the_section_10b_table(): void
    {
        $this->runSection10bScenario();

        $response = $this->actingAs($this->admin)->get(route('reports.index', [
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]))->assertOk();

        $profit = $response->viewData('profit');

        $this->assertSame(120_000, $profit['sales'], 'Sales 120,000');
        $this->assertSame(60_000, $profit['sale_returns'], 'Two remaining returns of 30,000');
        $this->assertSame(60_000, $profit['revenue'], 'Revenue 120,000 - 60,000');

        $this->assertSame(42_000, $profit['cogs'], 'COGS 42,000');
        $this->assertSame(22_000, $profit['cogs_reversed'], 'Cost reversed 22,000');
        $this->assertSame(40_000, $profit['gross_profit'], 'Gross profit 40,000');

        $this->assertSame(4_000, $profit['discounts_received'], 'Discounts received 4,000');
        $this->assertSame(10_000, $profit['write_offs'], 'Damage write-off 10,000');
        $this->assertSame(0, $profit['expenses'], 'No expenses in this scenario');

        $this->assertSame(34_000, $profit['net'], 'Net 34,000');
    }

    /** Expenses come off the net, which Section 10b's scenario does not exercise. */
    public function test_expenses_reduce_the_net(): void
    {
        $this->runSection10bScenario();

        Expense::create([
            'document_no' => 'EXP-00001',
            'title' => 'Rent',
            'expense_category_id' => ExpenseCategory::firstOrFail()->id,
            'amount' => 14_000,
            'expense_date' => today(),
            'user_id' => $this->admin->id,
        ]);

        $profit = $this->actingAs($this->admin)
            ->get(route('reports.index', [
                'from' => today()->subDay()->toDateString(),
                'to' => today()->addDay()->toDateString(),
            ]))
            ->viewData('profit');

        $this->assertSame(14_000, $profit['expenses']);
        $this->assertSame(20_000, $profit['net'], '34,000 less 14,000 of rent');
    }

    /** Section 4: activity_logs records every login, create, update and delete. */
    public function test_activity_is_recorded(): void
    {
        // activity_logs.user_id is a required FK, so the logger deliberately
        // skips anything it cannot attribute — a seeder run, for instance.
        $this->actingAs($this->admin);

        $category = Category::create(['name' => 'Logged']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'create',
            'module' => 'categories',
            'record_id' => $category->id,
        ]);

        $category->update(['name' => 'Renamed']);

        $update = ActivityLog::where('action', 'update')
            ->where('module', 'categories')
            ->latest('id')
            ->firstOrFail();

        // Section 8: the full previous version is stored in old_values.
        $this->assertSame('Logged', $update->old_values['name']);

        $category->delete();
        $this->assertDatabaseHas('activity_logs', ['action' => 'delete', 'module' => 'categories']);
    }

    public function test_logging_in_and_out_is_recorded(): void
    {
        // The seeded administrator no longer has a password this test could
        // know — that is the point of it — so give it one to log in with.
        $admin = \App\Models\User::where('email', 'admin@example.com')->firstOrFail();
        $admin->forceFill(['password' => 'a-strong-password-2026'])->save();

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'a-strong-password-2026',
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', ['action' => 'login', 'module' => 'auth']);

        $this->post(route('logout'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'logout', 'module' => 'auth']);
    }

    /**
     * Section 8c: the cache is cleared whenever settings are saved — "a stale
     * cache after a logo change is confusing and looks broken".
     */
    public function test_saving_settings_busts_the_cache(): void
    {
        $this->assertSame('Soran Store', setting('shop_name'));

        $this->actingAs($this->admin)->put(route('settings.update'), [
            'shop_name' => 'New Name',
            'primary_color' => '#ff0000',
            'secondary_color' => '#00ff00',
            'font_family' => 'system-ui, sans-serif',
            'sidebar_style' => 'expanded',
            'default_theme' => 'dark',
            'timezone' => 'Asia/Baghdad',
            'usd_rate' => 1400,
            'low_stock_threshold' => 3,
            'sku_prefix' => 'XX',
            'date_format' => 'd/m/Y',
            // Section 8c: the backup schedule is on this page too, and the form
            // posts every layer at once.
            'backup_frequency' => 'daily',
            'backup_time' => '02:15',
            'backup_weekday' => 5,
            'backup_keep_daily' => 30,
            'backup_keep_monthly' => 12,
            'label_size' => '50x30',
        ])->assertSessionHas('success');

        $this->assertSame('New Name', setting('shop_name'), 'The helper sees the new value immediately');
        $this->assertSame('1400', setting('usd_rate'));

        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'update']);
    }

    /**
     * Section 8: nothing dated before books_closed_before can be created,
     * edited or deleted.
     */
    public function test_a_closed_period_blocks_new_documents(): void
    {
        Setting::put('books_closed_before', today()->toDateString());
        Setting::flushCache();

        $category = Category::create(['name' => 'Test']);
        $product = Product::create([
            'name' => 'P', 'sku' => 'P1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 100, 'quantity' => 0,
        ]);

        $this->expectExceptionMessage('Locked: this date is in a closed period.');

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
            user: $this->admin,
            purchaseDate: today()->subWeek(),
        );
    }

    /** Section 4: the batches are the truth, and the recheck says so. */
    public function test_recheck_stock_finds_and_repairs_a_broken_cache(): void
    {
        $this->runSection10bScenario();

        $product = Product::firstOrFail();

        // Corrupt the cache the way a crash between two statements would.
        $product->forceFill(['quantity' => 999])->save();

        $mismatches = $this->actingAs($this->admin)
            ->get(route('stock.recheck'))
            ->assertOk()
            ->viewData('mismatches');

        $this->assertCount(1, $mismatches);
        $this->assertSame(999, $mismatches[0]['cached']);
        $this->assertSame(4, $mismatches[0]['batches'], 'The batches still say 4');

        $this->actingAs($this->admin)->post(route('stock.repair'))->assertSessionHas('success');

        $this->assertSame(4, $product->refresh()->quantity, 'Repaired from the batches');

        $this->actingAs($this->admin)
            ->get(route('stock.recheck'))
            ->assertOk()
            ->assertSee('The books are intact');
    }

    /** Runs the Section 10b scenario so the report has its numbers to read. */
    private function runSection10bScenario(): void
    {
        $category = Category::create(['name' => 'Test']);

        $product = Product::create([
            'name' => 'Product P', 'sku' => 'P', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 30_000, 'quantity' => 0,
        ]);

        $supplierA = Supplier::create(['name' => 'A']);
        $supplierB = Supplier::create(['name' => 'B']);
        $customer = Customer::create(['name' => 'C']);

        // T1: two lines, discount 4,000.
        app(PurchaseService::class)->create(
            supplier: $supplierA,
            lines: [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10_000],
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 12_000],
            ],
            user: $this->admin, purchaseDate: now(), discountAmount: 4_000,
        );

        // T2: paid in full.
        $pur2 = app(PurchaseService::class)->create(
            supplier: $supplierB,
            lines: [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 60_000,
        );

        // T3: sale spanning two batches.
        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 30_000]],
            user: $this->admin, saleDate: now(), amountPaid: 100_000,
        );

        // T4: three single-unit returns, then T5 deletes the third.
        $item = $sale->items()->firstOrFail();
        $returns = [];

        foreach (range(1, 3) as $i) {
            $returns[] = app(SaleReturnService::class)->create(
                sale: $sale,
                lines: [['sale_item_id' => $item->id, 'quantity' => 1]],
                user: $this->admin, returnDate: now(),
            );
        }

        app(SaleReturnService::class)->delete($returns[2], $this->admin);

        // T6: purchase return against the fully-paid purchase.
        app(PurchaseReturnService::class)->create(
            purchase: $pur2,
            lines: [['purchase_item_id' => $pur2->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->admin, returnDate: now(),
        );

        // T7: damage write-off.
        app(StockAdjustmentService::class)->create(
            product: $product,
            direction: StockAdjustment::DIRECTION_OUT,
            quantity: 1,
            reason: 'damage',
            user: $this->admin,
        );
    }
}
