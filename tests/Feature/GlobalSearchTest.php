<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One box for the whole shop.
 *
 * The half that matters is what it refuses to say. A search box that lists the
 * expenses it will not let you open has told you what the shop spends its money
 * on, which is exactly what withholding that screen was for. So every group is
 * behind the permission of the screen it leads to, and a reader without it gets
 * no row at all — not a locked one.
 */
class GlobalSearchTest extends TestCase
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
        $this->customer = Customer::create(['name' => 'Karwan Ahmed', 'phone' => '07701112233']);
        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);

        $this->product = Product::create([
            'name' => 'USB Cable 2m', 'sku' => 'USB2M', 'barcode' => '2000000000015',
            'category_id' => Category::create(['name' => 'Cables'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    private function sell(): Sale
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    private function expense(): Expense
    {
        return DB::transaction(fn () => Expense::create([
            'document_no' => app(DocumentNumberService::class)->next(DocumentNumberService::PREFIX_EXPENSE),
            'title' => 'Generator diesel',
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Fuel'])->id,
            'amount' => 25_000, 'expense_date' => now(), 'user_id' => $this->admin->id,
        ]));
    }

    /** @return array<string, list<array<string, mixed>>> keyed by group label */
    private function search(User $user, string $term): array
    {
        $groups = $this->actingAs($user)
            ->getJson(route('search', ['q' => $term]))
            ->assertOk()
            ->json('groups');

        return collect($groups)->keyBy('label')->map(fn ($g) => $g['items'])->all();
    }

    public function test_it_finds_a_document_by_the_number_printed_on_it(): void
    {
        $sale = $this->sell();

        $found = $this->search($this->admin, $sale->document_no);

        $this->assertSame($sale->document_no, $found[__('Sales')][0]['label']);
        $this->assertSame(route('sales.show', $sale), $found[__('Sales')][0]['url']);
        $this->assertStringContainsString('Karwan', $found[__('Sales')][0]['note']);
    }

    public function test_it_finds_a_product_by_name_sku_or_barcode(): void
    {
        foreach (['USB Cable', 'USB2M', '2000000000015'] as $term) {
            $found = $this->search($this->admin, $term);

            $this->assertSame('USB Cable 2m', $found[__('Products')][0]['label'], "searching for {$term}");
            $this->assertSame(route('products.show', $this->product), $found[__('Products')][0]['url']);
        }
    }

    public function test_it_finds_people_by_name_or_phone(): void
    {
        $found = $this->search($this->admin, 'Karwan');
        $this->assertSame('Karwan Ahmed', $found[__('People')][0]['label']);

        $found = $this->search($this->admin, '0770111');
        $this->assertSame('Karwan Ahmed', $found[__('People')][0]['label']);

        $found = $this->search($this->admin, 'Bazaar');
        $this->assertSame('Bazaar Mobile', $found[__('People')][0]['label']);
    }

    /** A reader who cannot remember where a screen lives can ask for it by name. */
    public function test_it_finds_a_screen_by_name(): void
    {
        $found = $this->search($this->admin, 'adjust');

        $this->assertSame(__('Stock adjustments'), $found[__('Screens')][0]['label']);
        $this->assertSame(route('stock-adjustments.index'), $found[__('Screens')][0]['url']);
    }

    // ------------------------------------------------------------ permissions

    /**
     * The point of the whole thing. A clerk who may sell but not see what the
     * shop spends must not learn it from a dropdown.
     */
    public function test_it_shows_nothing_a_reader_may_not_open(): void
    {
        $sale = $this->sell();
        $expense = $this->expense();

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(
            Permission::whereIn('key', ['sales.view', 'products.view'])->pluck('id')
        );

        // What they may see, they see.
        $found = $this->search($clerk, $sale->document_no);
        $this->assertArrayHasKey(__('Sales'), $found);

        // What they may not, they do not — not the group, not the row.
        $found = $this->search($clerk, $expense->document_no);
        $this->assertArrayNotHasKey(__('Expenses'), $found);
        $this->assertSame([], $found, 'Not even an empty group to hint that one exists');

        // Nor the people they have no permission for.
        $this->assertSame([], $this->search($clerk, 'Karwan'));
        $this->assertSame([], $this->search($clerk, 'Bazaar'));

        // Nor screens they cannot open.
        $found = $this->search($clerk, 'Expenses');
        $this->assertArrayNotHasKey(__('Screens'), $found);
    }

    public function test_a_reader_only_sees_the_screens_they_may_open(): void
    {
        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['sales.view'])->pluck('id'));

        $found = $this->search($clerk, 'sale');
        $labels = collect($found[__('Screens')] ?? [])->pluck('label');

        $this->assertContains(__('Sales history'), $labels);
        $this->assertNotContains(__('New sale'), $labels, 'They may read sales, not make one');
        $this->assertNotContains(__('Sale returns'), $labels);
    }

    // ----------------------------------------------------------------- limits

    public function test_one_letter_is_not_a_search(): void
    {
        $this->sell();

        $this->actingAs($this->admin)->getJson(route('search', ['q' => 'a']))
            ->assertOk()->assertExactJson(['groups' => []]);

        $this->actingAs($this->admin)->getJson(route('search', ['q' => '']))
            ->assertOk()->assertExactJson(['groups' => []]);
    }

    public function test_it_needs_somebody_logged_in(): void
    {
        $this->getJson(route('search', ['q' => 'USB']))->assertUnauthorized();
    }
}
