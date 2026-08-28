<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\MasterDataTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import and export the descriptive rows — and, more importantly, never the
 * stock or the ledger.
 *
 * Section 4 makes products.quantity a cache of SUM(quantity_remaining) and a
 * balance a cache of account_transactions. A spreadsheet that could write
 * either would put the cache out of step with the truth, silently. Half of this
 * file is about proving it cannot.
 */
class MasterDataTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->category = Category::create(['name' => 'Flash drives']);
    }

    // ------------------------------------------------------------------ export

    public function test_products_export_with_a_bom_so_excel_reads_kurdish_names(): void
    {
        Product::create([
            'name' => 'فلاش ٣٢ گیگا', 'sku' => 'USB32', 'category_id' => $this->category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 7,
        ]);

        $csv = app(MasterDataTransfer::class)->export('products');

        // Without the byte-order mark Excel on Windows renders every one of
        // these names as mojibake, and most names in this shop are Kurdish.
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('فلاش ٣٢ گیگا', $csv);
        $this->assertStringContainsString('USB32', $csv);
        $this->assertStringContainsString('Flash drives', $csv);

        // Stock is exported, because a stocktake list needs it.
        $this->assertStringContainsString('quantity', $csv);
        $this->assertStringContainsString('7', $csv);
    }

    public function test_the_export_route_returns_a_downloadable_file(): void
    {
        $response = $this->actingAs($this->admin)->get(route('data.export', 'products'));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('products-', $response->headers->get('content-disposition'));
    }

    public function test_an_empty_template_has_only_the_writable_columns(): void
    {
        $csv = app(MasterDataTransfer::class)->template('products');
        $header = trim(str_replace("\u{FEFF}", '', $csv));

        $this->assertSame('name,sku,barcode,category,unit,purchase_price,sale_price,reorder_level,is_active', $header);

        // quantity is exported but never offered as something to fill in.
        $this->assertStringNotContainsString('quantity', $header);
    }

    public function test_an_unknown_kind_of_data_is_a_404(): void
    {
        $this->actingAs($this->admin)->get(route('data.export', 'sales'))->assertNotFound();
    }

    // ------------------------------------------------------------------ import

    public function test_a_preview_changes_nothing(): void
    {
        $csv = $this->csv(['name,sku,category,purchase_price,sale_price', 'Cable,CBL1,Flash drives,1000,2000']);

        $result = app(MasterDataTransfer::class)->preview('products', $csv);

        $this->assertSame(1, $result['create']);
        $this->assertSame(0, Product::count(), 'A preview must not write anything');
    }

    public function test_an_import_adds_and_updates_and_leaves_the_rest_alone(): void
    {
        $existing = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $this->category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 7,
        ]);

        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price',
            'USB 32GB,USB32,Flash drives,11000,17000',
            'USB 64GB,USB64,Flash drives,18000,25000',
        ]);

        $result = app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $this->assertSame(1, $result['create']);
        $this->assertSame(1, $result['update']);
        $this->assertSame(0, $result['skip']);

        $existing->refresh();
        $this->assertSame(11_000, $existing->purchase_price);
        $this->assertSame(17_000, $existing->sale_price);

        // The one thing an import may never move.
        $this->assertSame(7, $existing->quantity);

        $added = Product::where('sku', 'USB64')->firstOrFail();
        $this->assertSame('USB 64GB', $added->name);
        $this->assertSame(0, $added->quantity, 'A new product starts empty; opening stock is an adjustment');
    }

    /**
     * The point of the whole design: stock is a cache of the batches, so a
     * spreadsheet cannot be allowed to set it.
     */
    public function test_a_file_that_tries_to_set_stock_is_told_so_rather_than_obeyed(): void
    {
        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $this->category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 7,
        ]);

        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price,quantity',
            'USB 32GB,USB32,Flash drives,10000,15000,999',
        ]);

        $result = app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $this->assertSame(7, $product->refresh()->quantity, 'Stock is untouched');

        // Refused out loud, not ignored quietly.
        $this->assertSame(1, $result['skip']);
        $this->assertStringContainsString('stock adjustment', $result['rows'][0]['note']);
    }

    public function test_a_file_that_tries_to_set_a_balance_is_told_so_too(): void
    {
        $supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $supplier->forceFill(['balance' => 50_000])->save();

        $csv = $this->csv([
            'name,phone,balance',
            'Bazaar Mobile,0770 000 0000,0',
        ]);

        $result = app(MasterDataTransfer::class)->import('suppliers', $csv, $this->admin);

        $this->assertSame(50_000, (int) $supplier->refresh()->balance);
        $this->assertStringContainsString('balance', $result['rows'][0]['note']);
    }

    public function test_a_blank_sku_is_generated_the_same_way_the_form_does(): void
    {
        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price',
            'Unnamed cable,,Flash drives,1000,2000',
        ]);

        app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $product = Product::firstOrFail();

        $this->assertStringStartsWith(setting('sku_prefix', 'SS'), $product->sku);
        $this->assertNotEmpty($product->barcode);
    }

    /**
     * A typo would otherwise add "Flah drives" beside "Flash drives", and the
     * shop would find out months later when a report split in two.
     */
    public function test_a_misspelled_category_is_reported_rather_than_created(): void
    {
        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price',
            'Cable,CBL1,Flah drives,1000,2000',
        ]);

        // Counted before rather than compared to a literal: the shop is seeded
        // with categories of its own, and the claim here is only that the
        // import added none.
        $before = Category::count();

        $result = app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $this->assertSame(1, $result['skip']);
        $this->assertSame(0, Product::count());
        $this->assertSame($before, Category::count(), 'No category was invented');
        $this->assertStringContainsString('Flah drives', $result['rows'][0]['note']);
    }

    public function test_a_bad_row_is_skipped_and_the_good_rows_still_land(): void
    {
        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price',
            ',CBL1,Flash drives,1000,2000',
            'Good cable,CBL2,Flash drives,1000,2000',
            'Negative,CBL3,Flash drives,-5,2000',
        ]);

        $result = app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $this->assertSame(1, $result['create']);
        $this->assertSame(2, $result['skip']);
        $this->assertSame(1, Product::count());
        $this->assertSame('Good cable', Product::firstOrFail()->name);
    }

    public function test_importing_the_export_a_second_time_changes_nothing(): void
    {
        Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $this->category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 7,
        ]);

        $transfer = app(MasterDataTransfer::class);

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $transfer->export('products'));

        $result = $transfer->import('products', $path, $this->admin);

        unlink($path);

        $this->assertSame(0, $result['create']);
        $this->assertSame(0, $result['update']);
        $this->assertSame(1, $result['unchanged'], 'A round trip is a no-op');
        $this->assertSame(1, Product::count());
    }

    /** Section 4: the Cash Customer "cannot be deleted or renamed". */
    public function test_the_cash_customer_cannot_be_changed_by_an_import(): void
    {
        $cash = Customer::cashCustomer();

        $csv = $this->csv(['name,phone', $cash->name.',0770 111 1111']);

        $result = app(MasterDataTransfer::class)->import('customers', $csv, $this->admin);

        $this->assertSame(1, $result['skip']);
        $this->assertNull($cash->refresh()->phone);
        $this->assertStringContainsString('part of the system', $result['rows'][0]['note']);
    }

    public function test_categories_can_reference_a_parent_created_earlier_in_the_same_file(): void
    {
        $csv = $this->csv([
            'name,parent',
            'Electronics,',
            'Chargers,Electronics',
        ]);

        $result = app(MasterDataTransfer::class)->import('categories', $csv, $this->admin);

        $this->assertSame(2, $result['create']);

        $child = Category::where('name', 'Chargers')->firstOrFail();
        $this->assertSame('Electronics', $child->parent->name);
    }

    public function test_a_file_with_no_recognisable_columns_is_refused(): void
    {
        $csv = $this->csv(['colour,size', 'red,large']);

        $this->expectExceptionMessageMatches('/no columns this import recognises/');

        app(MasterDataTransfer::class)->preview('products', $csv);
    }

    public function test_a_file_a_spreadsheet_left_a_blank_row_in_is_not_an_error(): void
    {
        $csv = $this->csv([
            'name,sku,category,purchase_price,sale_price',
            'Cable,CBL1,Flash drives,1000,2000',
            ',,,,',
            '',
        ]);

        $result = app(MasterDataTransfer::class)->import('products', $csv, $this->admin);

        $this->assertSame(1, $result['create']);
        $this->assertSame(0, $result['skip']);
    }

    // ------------------------------------------------------------- the screens

    public function test_the_page_lists_every_kind_and_says_what_it_will_not_touch(): void
    {
        $this->actingAs($this->admin)
            ->get(route('data.index'))
            ->assertOk()
            ->assertSee(__('Products'))
            ->assertSee(__('Suppliers'))
            ->assertSee(__('Customers'))
            ->assertSee('never stock or money', false);
    }

    public function test_the_upload_shows_a_preview_and_only_then_applies_it(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'name,sku,category,purchase_price,sale_price',
            'Cable,CBL1,Flash drives,1000,2000',
        ]));

        $preview = $this->actingAs($this->admin)
            ->post(route('data.preview', 'products'), ['file' => $file]);

        $preview->assertOk()->assertSee(__('Apply :count changes', ['count' => 1]));

        $this->assertSame(0, Product::count(), 'The preview alone writes nothing');

        $token = $preview->viewData('token');

        $this->actingAs($this->admin)
            ->post(route('data.import', 'products'), ['token' => $token])
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Product::count());

        // The uploaded file does not linger on disk afterwards.
        Storage::disk('local')->assertMissing($token);
    }

    /** A token is a path this app wrote a moment ago, not a filename to guess. */
    public function test_a_made_up_token_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('data.import', 'products'), ['token' => '../../.env'])
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('error');
    }

    public function test_importing_needs_permission_to_both_add_and_change(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->permissions()->sync(
            \App\Models\Permission::whereIn('key', ['data.manage', 'products.view', 'products.create'])->pluck('id')
        );

        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('products.csv', "name,sku\nCable,CBL1\n");

        // View alone is enough to export…
        $this->actingAs($user)->get(route('data.export', 'products'))->assertOk();

        // …but adding without being allowed to change is not enough to import.
        $this->actingAs($user)
            ->post(route('data.preview', 'products'), ['file' => $file])
            ->assertForbidden();
    }

    /**
     * Write rows to a temporary CSV and return its path.
     *
     * @param  list<string>  $lines
     */
    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';

        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }
}
