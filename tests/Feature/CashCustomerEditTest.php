<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a sale to the Cash Customer.
 *
 * "Paid in full" is not a condition to check on the way in for this customer —
 * it is the outcome to arrange. They are standing at the counter: adding a line
 * means more money handed over, removing one means change handed back. Refusing
 * the edit and asking for a refund to be recorded first describes a customer who
 * owes something, which this one never does.
 */
class CashCustomerEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Customer $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->cash = Customer::where('is_system', true)->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    private function cashSale(int $quantity): \App\Models\Sale
    {
        return app(SaleService::class)->create(
            customer: $this->cash,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: $quantity * 15_000,
        );
    }

    private function edit(\App\Models\Sale $sale, int $quantity): void
    {
        app(SaleService::class)->update(
            sale: $sale,
            customer: $this->cash,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 15_000]],
            user: $this->admin,
            saleDate: now(),
        );
    }

    public function test_adding_a_line_takes_the_extra_money(): void
    {
        $sale = $this->cashSale(2);

        $this->edit($sale, 3);

        $sale->refresh();

        $this->assertSame(45_000, (int) $sale->total_amount);
        $this->assertSame(45_000, $sale->amountPaid(), 'Still square');
        $this->assertSame(0, $sale->amountDue());
        $this->assertSame(0, (int) $this->cash->refresh()->balance, 'The Cash Customer never owes');

        // The extra 15,000 went into the till as its own payment.
        $this->assertSame(15_000, (int) Payment::where('payable_id', $sale->id)
            ->where('direction', Payment::DIRECTION_IN)
            ->orderByDesc('id')->value('amount'));
    }

    public function test_removing_a_line_hands_the_change_back(): void
    {
        $sale = $this->cashSale(3);

        $this->edit($sale, 1);

        $sale->refresh();

        $this->assertSame(15_000, (int) $sale->total_amount);
        $this->assertSame(15_000, $sale->amountPaid());
        $this->assertSame(0, $sale->amountDue());
        $this->assertSame(0, (int) $this->cash->refresh()->balance);

        // 30,000 of the 45,000 went back out of the till.
        $this->assertSame(30_000, (int) Payment::where('payable_id', $sale->id)
            ->where('direction', Payment::DIRECTION_OUT)
            ->orderByDesc('id')->value('amount'));
    }

    public function test_the_stock_follows_the_edit(): void
    {
        $sale = $this->cashSale(2);
        $this->assertSame(18, $this->product->refresh()->quantity);

        $this->edit($sale, 5);
        $this->assertSame(15, $this->product->refresh()->quantity);

        $this->edit($sale, 1);
        $this->assertSame(19, $this->product->refresh()->quantity);
    }

    /** A named customer keeps the rule: a smaller total needs a refund first. */
    public function test_a_named_customer_still_has_to_be_refunded_first(): void
    {
        $named = Customer::create(['name' => 'Karwan']);

        $sale = app(SaleService::class)->create(
            customer: $named,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 45_000,
        );

        $this->expectExceptionMessageMatches('/less than the/');

        app(SaleService::class)->update(
            sale: $sale,
            customer: $named,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin,
            saleDate: now(),
        );
    }
}
