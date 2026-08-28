<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
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

/**
 * Every record that keeps a history shows it on its own page.
 *
 * activity_logs has recorded all of this since the system was built. Reading it
 * meant the whole shop's log in one list; now each record carries its own, and
 * this checks the section is actually on every page rather than on the two
 * somebody remembered.
 */
class RecordHistoryReachTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_every_document_and_person_carries_its_own_history(): void
    {
        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        $supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $customer = Customer::create(['name' => 'Karwan']);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $saleReturn = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $purchaseReturn = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->first()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $adjustment = app(StockAdjustmentService::class)->create(
            product: $product->refresh(), direction: StockAdjustment::DIRECTION_OUT,
            quantity: 1, reason: 'damage', user: $this->admin,
        );

        $expense = Expense::create([
            'document_no' => 'EXP-00001',
            'title' => 'Shop electricity',
            'expense_category_id' => ExpenseCategory::first()->id,
            'amount' => 50_000,
            'expense_date' => today(),
            'user_id' => $this->admin->id,
        ]);

        $this->post(route('payments.store'), [
            'payable_type' => 'sale', 'payable_id' => $sale->id,
            'amount' => 20_000, 'direction' => Payment::DIRECTION_IN,
            'payment_method' => 'cash', 'paid_at' => today()->toDateString(),
        ]);

        $payment = Payment::latest('id')->firstOrFail();

        $pages = [
            'product' => route('products.show', $product),
            'sale' => route('sales.show', $sale),
            'purchase' => route('purchases.show', $purchase),
            'sale return' => route('sale-returns.show', $saleReturn),
            'purchase return' => route('purchase-returns.show', $purchaseReturn),
            'adjustment' => route('stock-adjustments.show', $adjustment),
            'expense' => route('expenses.show', $expense),
            'payment' => route('payments.show', $payment),
            'supplier' => route('suppliers.show', $supplier),
            'customer' => route('customers.show', $customer),
        ];

        foreach ($pages as $what => $url) {
            $this->actingAs($this->admin)->get($url)
                ->assertOk()
                ->assertSee(__('History'), false);

            $this->assertTrue(true, "{$what} carries its history");
        }
    }

    /** No key, no query, no section — the component asks for itself. */
    public function test_the_section_is_withheld_without_the_activity_log_permission(): void
    {
        $customer = Customer::create(['name' => 'Karwan']);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'customers.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee(__('History'), false);
    }
}
