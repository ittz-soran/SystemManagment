<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SaleService;
use App\Services\SecondHandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Several of the same second-hand thing — PROJECT_DOC Section 9.
 *
 * Soran, 2026-10-20: *"today i bought 2 laptop dell both same"*. He chose one
 * form making separate items: each its own row holding one unit, with its own
 * barcode, on one purchase — not one item with 2 in stock, because two used
 * laptops are two machines.
 */
class SecondHandQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    private function postTwoLaptops(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('second-hand.store'), [
            'name' => 'Dell Latitude 5490',
            'condition_note' => 'i5, 8GB',
            'quantity' => 2,
            'cost' => 300_000,
            'sale_price' => 380_000,
            'amount_paid' => 600_000,
            'payment_method' => 'cash',
            'seller_name' => 'Hawkar',
            'bought_at' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    public function test_two_of_the_same_are_two_items_each_with_its_own_barcode(): void
    {
        $this->postTwoLaptops()->assertSessionHasNoErrors()->assertRedirect(route('second-hand.index'));

        $laptops = Product::used()->where('name', 'Dell Latitude 5490')->get();

        $this->assertCount(2, $laptops);
        $this->assertSame([1, 1], $laptops->pluck('quantity')->all());
        $this->assertCount(2, $laptops->pluck('sku')->unique());
        $this->assertCount(2, $laptops->pluck('barcode')->unique());

        foreach ($laptops as $laptop) {
            $this->assertSame('i5, 8GB', $laptop->condition_note);
            $this->assertSame(380_000, (int) $laptop->sale_price);
            $this->assertSame(300_000, (int) $laptop->stockBatches()->sole()->unit_cost);
        }
    }

    /** One deal with one person is one purchase, a line for each machine. */
    public function test_they_go_on_one_purchase_at_the_price_agreed_each(): void
    {
        $this->postTwoLaptops()->assertSessionHasNoErrors();

        $purchase = Purchase::sole();

        $this->assertCount(2, $purchase->items);
        $this->assertSame([300_000, 300_000], $purchase->items->pluck('unit_price')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(600_000, (int) $purchase->grand_total);
        $this->assertSame(0, (int) Supplier::walkIns()->sole()->balance);
    }

    /**
     * ⚠️ Paid now is checked against the whole deal. The old check compared it
     * with the price of ONE, and would have refused paying for both.
     */
    public function test_paying_part_of_the_whole_deal_leaves_the_rest_owed(): void
    {
        $this->postTwoLaptops(['amount_paid' => 450_000])->assertSessionHasNoErrors();

        $this->assertSame(150_000, (int) Supplier::walkIns()->sole()->balance);
    }

    public function test_paying_more_than_the_whole_deal_is_refused(): void
    {
        $this->postTwoLaptops(['amount_paid' => 600_001])->assertSessionHasErrors('amount_paid');

        $this->assertSame(0, Product::used()->count());
    }

    /** A slip of the finger is not two hundred products. */
    public function test_how_many_is_between_one_and_twenty(): void
    {
        $this->postTwoLaptops(['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->postTwoLaptops(['quantity' => SecondHandService::MOST_AT_ONCE + 1])->assertSessionHasErrors('quantity');

        $this->assertSame(0, Product::used()->count());

        $this->expectException(RuntimeException::class);
        app(SecondHandService::class)->buy([
            'name' => 'Dell', 'cost' => 1, 'sale_price' => 2, 'seller_name' => 'Hawkar',
            'quantity' => SecondHandService::MOST_AT_ONCE + 1,
        ], $this->admin);
    }

    /** Selling one leaves the other on the shelf, at its own cost. */
    public function test_selling_one_leaves_the_other(): void
    {
        $this->postTwoLaptops()->assertSessionHasNoErrors();

        [$first, $second] = Product::used()->orderBy('id')->get()->all();

        $sale = app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $first->id, 'quantity' => 1, 'unit_price' => 380_000]],
            user: $this->admin, saleDate: now(), amountPaid: 380_000,
        );

        $this->assertSame(80_000, $sale->items->sole()->lineTotal() - 300_000);
        $this->assertSame(0, $first->fresh()->quantity);
        $this->assertSame(1, $second->fresh()->quantity);
    }

    public function test_the_form_asks_how_many(): void
    {
        $this->actingAs($this->admin)->get(route('second-hand.create'))
            ->assertOk()
            ->assertSee('name="quantity"', false)
            ->assertSee('max="'.SecondHandService::MOST_AT_ONCE.'"', false);
    }
}
