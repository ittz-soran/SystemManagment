<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a document still owes, once part of it has come back.
 *
 * **Soran found this on INV-00027, 2026-09-14.** A 180,000 sale with 45,000
 * returned on one line. The customer's balance was right — the ledger had done
 * its job — but the sale's own Due still read 180,000, and nothing on the page
 * said otherwise. He was being shown a debt that no longer existed.
 *
 * The cause: `amountDue()` was `total_amount - amountPaid()`, and a return is
 * not a payment. Section 7 settles a refund against the customer's BALANCE
 * first, so the document it came off never heard about it.
 *
 * ⚠️ The fix is not "subtract the return's total". A refund clears the debt
 * first and hands the rest back in cash, and cash handed back never reduced a
 * debt — there was none left to reduce. So it subtracts what was APPLIED, which
 * `LedgerService::post` already records. The four cases below are the whole
 * reason that distinction matters.
 */
class DueAfterReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'Router Olax Battery', 'sku' => 'MT30', 'unit' => 'pcs',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 30_000, 'sale_price' => 45_000,
        ]);

        // Stock to sell, so FIFO has a layer to draw from.
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Erbil Wholesale']),
            lines: [['product_id' => $this->product->id, 'quantity' => 50, 'unit_price' => 30_000]],
            user: $this->user,
            purchaseDate: today()->subDays(3),
        );
    }

    /** Four units at 45,000 — the 180,000 invoice Soran was looking at. */
    private function aSale(int $paid = 0): Sale
    {
        return app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Hawkar Osman']),
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 45_000]],
            user: $this->user,
            saleDate: today(),
            amountPaid: $paid,
        );
    }

    /** One unit back, 45,000 of it. */
    private function returnOne(Sale $sale): void
    {
        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->user,
            returnDate: today(),
        );
    }

    // ---- The bug itself -------------------------------------------------

    /** ⚠️ INV-00027: nothing paid, one line back. 180,000 − 45,000 = 135,000. */
    public function test_a_return_comes_off_what_the_document_still_owes(): void
    {
        $sale = $this->aSale();

        $this->assertSame(180_000, $sale->amountDue());

        $this->returnOne($sale);

        $this->assertSame(135_000, $sale->fresh()->amountDue(),
            'the sale still claimed the whole 180,000 after 45,000 came back');
    }

    /** And the screen says it, which is where he saw the wrong number. */
    public function test_the_sale_page_shows_the_reduced_figure(): void
    {
        $sale = $this->aSale();
        $this->returnOne($sale);

        $this->actingAs($this->user)->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('135,000');
    }

    /**
     * ⚠️ And the page SAYS where the missing money went.
     *
     * A return settles against the customer's balance, not against this
     * document, so Due drops with no payment appearing above it. Without a line
     * saying so a reader sees a 180,000 total, nothing paid and 135,000 due —
     * three true numbers that look like an error, which is how this was found.
     */
    public function test_the_page_says_what_the_returns_took_off(): void
    {
        $sale = $this->aSale();
        $this->returnOne($sale);

        $this->actingAs($this->user)->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee(__('Taken off by returns'))
            ->assertSee('45,000');
    }

    /** And so does the screen that takes the next payment. */
    public function test_the_payment_screen_says_it_too(): void
    {
        $sale = $this->aSale();
        $this->returnOne($sale);

        $this->actingAs($this->user)
            ->get(route('payments.create', ['payable_type' => 'sale', 'payable_id' => $sale->id]))
            ->assertOk()
            ->assertSee(__('Returned'))
            // And it offers to settle what is actually owed, not the old total.
            ->assertSee('135,000');
    }

    // ---- Why it is the APPLIED figure, not the return's total ----------

    /**
     * ⚠️ Paid in full, then returned: the shop hands back cash, and the
     * document owes nothing either way.
     *
     * Subtracting the return's total here would make Due read −45,000 — the
     * shop owing a customer money it has already handed across the counter.
     */
    public function test_a_return_paid_back_in_cash_leaves_the_document_settled(): void
    {
        $sale = $this->aSale(paid: 180_000);

        $this->assertSame(0, $sale->amountDue());

        $this->returnOne($sale);

        $this->assertSame(0, $sale->fresh()->amountDue());
    }

    /** Part paid: only the part the balance could absorb comes off. */
    public function test_a_part_paid_sale_takes_off_only_what_the_balance_absorbed(): void
    {
        // Paid 160,000, so 20,000 is owed. A 45,000 refund clears that 20,000
        // and 25,000 goes back across the counter.
        $sale = $this->aSale(paid: 160_000);

        $this->returnOne($sale);

        $this->assertSame(0, $sale->fresh()->amountDue());
    }

    /** Half paid, the whole refund absorbed by the balance. */
    public function test_a_half_paid_sale_takes_the_whole_refund_off(): void
    {
        $sale = $this->aSale(paid: 100_000);

        $this->returnOne($sale);

        // 180,000 owed less 100,000 paid less 45,000 returned.
        $this->assertSame(35_000, $sale->fresh()->amountDue());
    }

    /** A deleted return puts the debt back, because its reversal is a row too. */
    public function test_deleting_the_return_puts_the_debt_back(): void
    {
        $sale = $this->aSale();
        $this->returnOne($sale);

        $this->assertSame(135_000, $sale->fresh()->amountDue());

        app(SaleReturnService::class)->delete($sale->returns()->firstOrFail(), $this->user);

        $this->assertSame(180_000, $sale->fresh()->amountDue());
    }

    // ---- The same bug on the buying side -------------------------------

    /** A purchase return comes off what the shop still owes its supplier. */
    public function test_a_purchase_return_comes_off_what_the_shop_owes(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Duhok Traders']),
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 45_000]],
            user: $this->user,
            purchaseDate: today(),
        );

        $this->assertSame(180_000, $purchase->amountDue());

        app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [[
                'purchase_item_id' => $purchase->items()->firstOrFail()->id,
                'quantity' => 1,
                'unit_price' => 45_000,
            ]],
            user: $this->user,
            returnDate: today(),
        );

        $this->assertSame(135_000, Purchase::findOrFail($purchase->id)->amountDue());
    }
}
