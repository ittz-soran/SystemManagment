<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 4: "direction = out is money leaving the till — a cash refund to a
 * customer, or paying a supplier."
 *
 * All four cases in one place, because getting one backwards makes the cash
 * report wrong in a way the balances would not reveal.
 */
class PaymentDirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'P', 'sku' => 'P1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 30_000, 'quantity' => 0,
        ]);
    }

    public function test_paying_a_supplier_is_money_leaving_the_till(): void
    {
        $supplier = Supplier::create(['name' => 'A']);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->user, purchaseDate: now(), amountPaid: 60_000,
        );

        $payment = $purchase->payments()->sole();

        $this->assertSame(Payment::DIRECTION_OUT, $payment->direction, 'Paying a supplier is cash out');
        $this->assertSame(60_000, $payment->amount, 'The amount is always positive');

        // And the document still knows it is settled.
        $this->assertSame(60_000, $purchase->amountPaid());
        $this->assertSame(0, $purchase->amountDue());
        $this->assertSame(0, (int) $supplier->refresh()->balance);
    }

    public function test_a_customer_paying_an_invoice_is_money_coming_in(): void
    {
        $supplier = Supplier::create(['name' => 'A']);
        $customer = Customer::create(['name' => 'C']);

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 50_000,
        );

        $payment = $sale->payments()->sole();

        $this->assertSame(Payment::DIRECTION_IN, $payment->direction, 'A customer paying is cash in');
        $this->assertSame(50_000, $sale->amountPaid());
        $this->assertSame(10_000, $sale->amountDue());
    }

    public function test_a_cash_refund_is_money_leaving_the_till(): void
    {
        $supplier = Supplier::create(['name' => 'A']);
        $customer = Customer::create(['name' => 'C']);

        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        // Paid in full, so the customer owes nothing and the whole refund is cash.
        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 60_000,
        );

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->user, returnDate: now(),
        );

        $payment = $return->payments()->sole();

        $this->assertSame(Payment::DIRECTION_OUT, $payment->direction, 'A cash refund is cash out');
        $this->assertSame(30_000, $payment->amount);
        $this->assertSame(0, (int) $customer->refresh()->balance, 'The balance floors at zero');
    }

    public function test_cash_back_from_a_supplier_is_money_coming_in(): void
    {
        $supplier = Supplier::create(['name' => 'A']);

        // Paid in full, so a return has no debt to offset and comes back as cash.
        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->user, purchaseDate: now(), amountPaid: 60_000,
        );

        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $payment = $return->payments()->sole();

        $this->assertSame(Payment::DIRECTION_IN, $payment->direction, 'Cash back from a supplier is cash in');
        $this->assertSame(30_000, $payment->amount);
        $this->assertSame(0, (int) $supplier->refresh()->balance, 'Never negative');
    }

    /**
     * The till nets correctly across a whole trading day: bought for 60,000,
     * sold for 60,000, refunded 30,000, and got 30,000 back from the supplier.
     */
    public function test_the_till_nets_correctly_across_all_four_cases(): void
    {
        $supplier = Supplier::create(['name' => 'A']);
        $customer = Customer::create(['name' => 'C']);

        $purchase = app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->user, purchaseDate: now(), amountPaid: 60_000,
        );

        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 60_000,
        );

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->user, returnDate: now(),
        );

        app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $in = (int) Payment::where('direction', Payment::DIRECTION_IN)->sum('amount');
        $out = (int) Payment::where('direction', Payment::DIRECTION_OUT)->sum('amount');

        $this->assertSame(90_000, $in, 'Sale 60,000 + supplier refund 30,000');
        $this->assertSame(90_000, $out, 'Purchase 60,000 + customer refund 30,000');
        $this->assertSame(0, $in - $out, 'The till nets to zero across the day');
    }
}
