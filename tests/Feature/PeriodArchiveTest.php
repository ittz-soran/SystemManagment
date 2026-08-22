<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PeriodArchiveService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Archiving a period: write it to a file, stop showing it, delete nothing.
 *
 * The "delete nothing" half is the part with teeth. A purchase from months ago
 * can still own stock on the shelf, every sale's movements are the only record
 * of what its units cost, and a balance is a running total of the ledger — so
 * most of this file checks that the old rows are still there and still working
 * after the period has been archived.
 */
class PeriodArchiveTest extends TestCase
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

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Flash drives']);

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $this->customer = Customer::create(['name' => 'Karwan']);
    }

    /** An old purchase and sale, and a recent one. */
    private function tradeAcrossMonths(): void
    {
        $this->travelTo(now()->subMonths(4));

        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(),
        );

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 45_000,
        );

        $this->travelBack();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 30_000,
        );
    }

    private function archive(): array
    {
        return app(PeriodArchiveService::class)
            ->archive(now()->subMonths(2)->endOfMonth(), $this->admin);
    }

    // ------------------------------------------------------------------ export

    public function test_the_export_is_a_zip_of_spreadsheets(): void
    {
        $this->tradeAcrossMonths();

        $path = app(PeriodArchiveService::class)->export(null, now());

        $this->assertFileExists($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        foreach (['sales.csv', 'purchases.csv', 'payments.csv', 'ledger.csv', 'stock_movements.csv', 'README.txt'] as $file) {
            $this->assertContains($file, $names);
        }

        $sales = $zip->getFromName('sales.csv');

        // Excel on Windows needs the byte-order mark to read Kurdish names.
        $this->assertStringStartsWith("\u{FEFF}", $sales);
        $this->assertStringContainsString('Karwan', $sales);
        $this->assertStringContainsString('document_no', $sales);

        $zip->close();
        unlink($path);
    }

    public function test_the_export_covers_only_the_period_asked_for(): void
    {
        $this->tradeAcrossMonths();

        $old = app(PeriodArchiveService::class)->summary(null, now()->subMonths(2));
        $all = app(PeriodArchiveService::class)->summary(null, now());

        $this->assertSame(1, $old['sales'], 'Only the four-month-old sale');
        $this->assertSame(2, $all['sales']);
    }

    // ----------------------------------------------------------------- hiding

    public function test_archiving_hides_the_old_period_and_deletes_nothing(): void
    {
        $this->tradeAcrossMonths();

        $this->assertSame(2, Sale::count());
        $this->assertSame(1, Purchase::count());

        $this->archive();

        // Still every row, exactly as before.
        $this->assertSame(2, Sale::count());
        $this->assertSame(1, Purchase::count());
        $this->assertDatabaseCount('stock_movements', 3);

        // But the lists only show the recent one.
        $this->assertSame(1, Sale::visible()->count());
        $this->assertSame(0, Purchase::visible()->count());

        // And asking for them brings them back.
        $this->assertSame(2, Sale::visible(true)->count());
        $this->assertSame(1, Purchase::visible(true)->count());
    }

    /**
     * The reason this hides instead of deleting: those old rows are still doing
     * work. The purchase four months ago owns the batch today's stock sits in.
     */
    public function test_stock_costs_and_balances_still_work_after_archiving(): void
    {
        $this->tradeAcrossMonths();

        $stockBefore = $this->product->refresh()->quantity;
        $balanceBefore = (int) $this->customer->refresh()->balance;

        $this->archive();

        $this->assertSame($stockBefore, $this->product->refresh()->quantity);
        $this->assertSame($balanceBefore, (int) $this->customer->refresh()->balance);

        // And FIFO keeps consuming the archived purchase's batch.
        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 15_000,
        );

        $movement = \App\Models\StockMovement::where('reference_type', 'sale')
            ->where('reference_id', $sale->id)->firstOrFail();

        $this->assertSame(10_000, (int) $movement->unit_cost, 'Still costed from the archived purchase');
        $this->assertSame($stockBefore - 1, $this->product->refresh()->quantity);
    }

    /** Reports are about a date range, so they must see the archived period. */
    public function test_reports_still_include_the_archived_period(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        $this->actingAs($this->admin)
            ->get(route('reports.index', [
                'from' => now()->subMonths(6)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk();

        // The underlying totals, which the report reads, are unchanged.
        $this->assertSame(2, Sale::whereDate('sale_date', '>=', now()->subMonths(6))->count());
    }

    public function test_archiving_freezes_the_period_by_default(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        Setting::flushCache();

        $this->assertNotNull(setting('books_closed_before'));
        $this->assertSame(setting('archived_before'), setting('books_closed_before'));
    }

    public function test_it_can_archive_without_freezing(): void
    {
        $this->tradeAcrossMonths();

        app(PeriodArchiveService::class)
            ->archive(now()->subMonths(2)->endOfMonth(), $this->admin, freeze: false);

        Setting::flushCache();

        $this->assertNotNull(setting('archived_before'));
        $this->assertNull(setting('books_closed_before'));
    }

    public function test_showing_it_again_needs_no_restore_because_nothing_left(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        $this->assertSame(1, Sale::visible()->count());

        app(PeriodArchiveService::class)->unhide($this->admin);
        Setting::flushCache();

        $this->assertSame(2, Sale::visible()->count());
    }

    public function test_the_archive_is_recorded_in_the_activity_log(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        $this->assertDatabaseHas('activity_logs', ['module' => 'settings', 'action' => 'update']);

        $this->assertStringContainsString(
            'Nothing was deleted',
            (string) \App\Models\ActivityLog::latest('id')->firstOrFail()->description,
        );
    }

    // ------------------------------------------------------------- the screens

    public function test_the_lists_say_what_they_are_not_showing(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        $this->actingAs($this->admin)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertSee('archived and not shown', false)
            ->assertSee(__('Show them'));

        // And following that shows them.
        $this->actingAs($this->admin)
            ->get(route('sales.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee(__('Hide them again'));
    }

    public function test_the_totals_count_the_same_rows_the_list_shows(): void
    {
        $this->tradeAcrossMonths();
        $this->archive();

        $hidden = $this->actingAs($this->admin)->get(route('payments.index'))->assertOk();
        $shown = $this->actingAs($this->admin)->get(route('payments.index', ['archived' => 1]))->assertOk();

        $this->assertLessThan(
            $shown->viewData('totalIn'),
            $hidden->viewData('totalIn'),
            'The archived payment must not be counted in a total the list does not show'
        );
    }

    public function test_the_page_offers_export_and_archive(): void
    {
        $this->actingAs($this->admin)
            ->get(route('data.index'))
            ->assertOk()
            ->assertSee(__('Export a period'))
            ->assertSee(__('Archive a period'))
            ->assertSee(__('Nothing is deleted.'));
    }

    public function test_archiving_from_the_screen_downloads_the_file_and_hides_the_period(): void
    {
        $this->tradeAcrossMonths();

        $response = $this->actingAs($this->admin)->post(route('data.period.archive'), [
            'through' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'freeze' => 1,
        ]);

        $response->assertOk()->assertHeader('content-type', 'application/zip');

        Setting::flushCache();

        $this->assertNotNull(setting('archived_before'));
        $this->assertSame(2, Sale::count(), 'Still every sale');
        $this->assertSame(1, Sale::visible()->count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->from(route('data.index'))
            ->post(route('data.period.archive'), ['through' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('through');
    }

    public function test_archiving_needs_permission_to_manage_settings(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->permissions()->sync(
            \App\Models\Permission::whereIn('key', ['sales.view', 'reports.view'])->pluck('id')
        );

        $this->actingAs($user)
            ->post(route('data.period.archive'), ['through' => now()->subMonth()->toDateString()])
            ->assertForbidden();

        // But the plain export is a reporting job, not an administrative one.
        $this->actingAs($user)
            ->post(route('data.period.export'), ['to' => now()->toDateString()])
            ->assertOk();
    }
}
