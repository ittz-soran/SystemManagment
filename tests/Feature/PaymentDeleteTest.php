<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Undoing a payment.
 *
 * Section 8b: a delete is a reversal plus a hidden record, never a way to skip
 * the reversal. A payment that settled a debt put money against it, so removing
 * it puts the debt back — and a payment that settled nothing, a refund on a
 * return, leaves the balances where they were.
 */
class PaymentDeleteTest extends TestCase
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
        $this->customer = Customer::create(['name' => 'Karwan']);
        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    private function buy(int $paid = 0): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: $paid,
        );
    }

    private function sell(int $paid = 0): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: $paid,
        );
    }

    public function test_deleting_a_customer_payment_puts_the_debt_back(): void
    {
        $this->buy();
        $sale = $this->sell(paid: 40_000);

        $this->assertSame(20_000, (int) $this->customer->refresh()->balance);
        $payment = Payment::where('payable_id', $sale->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('payments.destroy', $payment))
            ->assertRedirect(route('sales.show', $sale));

        $this->assertSame(60_000, (int) $this->customer->refresh()->balance, 'The whole invoice is owed again');
        $this->assertSame(0, $sale->refresh()->amountPaid());
        $this->assertSame(60_000, $sale->amountDue());
        $this->assertSoftDeleted('payments', ['id' => $payment->id]);
    }

    public function test_deleting_a_supplier_payment_puts_the_debt_back(): void
    {
        $purchase = $this->buy(paid: 50_000);

        $this->assertSame(150_000, (int) $this->supplier->refresh()->balance);
        $payment = Payment::where('payable_id', $purchase->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('payments.destroy', $payment))
            ->assertRedirect(route('purchases.show', $purchase));

        $this->assertSame(200_000, (int) $this->supplier->refresh()->balance);
        $this->assertSame(0, $purchase->refresh()->amountPaid());
    }

    /**
     * A refund on a return moved no balance when it was recorded — the return
     * had already done that — so removing it must move none either.
     */
    public function test_deleting_a_refund_leaves_the_balances_alone(): void
    {
        $this->buy();
        $sale = $this->sell(paid: 60_000);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $refund = Payment::where('payable_type', 'sale_return')
            ->where('payable_id', $return->id)->firstOrFail();

        $before = (int) $this->customer->refresh()->balance;

        $this->actingAs($this->admin)
            ->delete(route('payments.destroy', $refund))
            ->assertRedirect();

        $this->assertSame($before, (int) $this->customer->refresh()->balance);
        $this->assertSoftDeleted('payments', ['id' => $refund->id]);
    }

    public function test_a_payment_in_a_closed_period_cannot_be_deleted(): void
    {
        $this->buy();
        $sale = $this->sell(paid: 40_000);
        $payment = Payment::where('payable_id', $sale->id)->firstOrFail();

        Setting::put('books_closed_before', today()->addDay()->toDateString());

        $this->actingAs($this->admin)
            ->from(route('payments.show', $payment))
            ->delete(route('payments.destroy', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'deleted_at' => null]);
        $this->assertSame(20_000, (int) $this->customer->refresh()->balance);
    }

    public function test_it_needs_the_permission(): void
    {
        $this->buy();
        $sale = $this->sell(paid: 40_000);
        $payment = Payment::where('payable_id', $sale->id)->firstOrFail();

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['payments.view'])->pluck('id'));

        $this->actingAs($clerk)
            ->delete(route('payments.destroy', $payment))
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'deleted_at' => null]);
    }
}
