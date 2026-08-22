<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\PurchaseService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages for the three documents that had none.
 *
 * A payment, an expense and a stock adjustment each carry a number that the
 * rest of the system already points at — the FIFO trail, the ledger, the
 * Payments card on every document — but there was nowhere for those numbers to
 * lead. Each now has a page, and each page answers the question its number
 * raises somewhere else: what did this payment leave owing, what did this
 * adjustment do to the batches.
 */
class DocumentPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        Customer::firstOrCreate(['name' => 'Karwan']);
    }

    private function buy(int $paid = 0): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: $paid,
        );
    }

    // ------------------------------------------------------------- payments

    public function test_a_payment_page_names_the_document_and_what_is_still_due(): void
    {
        $purchase = $this->buy(paid: 40_000);
        $payment = Payment::where('payable_id', $purchase->id)->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->get(route('payments.show', $payment))->assertOk();

        $response->assertSee($payment->document_no);
        $response->assertSee('40,000');
        $response->assertSee($purchase->document_no);
        $response->assertSee($this->supplier->name);

        // The question a payment is read to answer.
        $response->assertSee('60,000');

        // And the document it settles is a link, not just a name.
        $this->assertStringContainsString(
            'href="'.route('purchases.show', $purchase).'"',
            $response->getContent(),
        );
    }

    public function test_the_payments_card_on_a_document_leads_to_the_payment(): void
    {
        $purchase = $this->buy(paid: 40_000);
        $payment = Payment::where('payable_id', $purchase->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('purchases.show', $purchase))->assertOk()
            ->assertSee(route('payments.show', $payment), escape: false);
    }

    // ------------------------------------------------------------- expenses

    public function test_an_expense_has_a_page(): void
    {
        $expense = Expense::create([
            'document_no' => app(DocumentNumberService::class)->next(DocumentNumberService::PREFIX_EXPENSE),
            'title' => 'Generator diesel',
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Fuel'])->id,
            'amount' => 25_000,
            'expense_date' => now(),
            'user_id' => $this->admin->id,
            'notes' => 'Two jerrycans',
        ]);

        $this->actingAs($this->admin)->get(route('expenses.show', $expense))->assertOk()
            ->assertSee($expense->document_no)
            ->assertSee('Generator diesel')
            ->assertSee('25,000')
            ->assertSee('Fuel')
            ->assertSee('Two jerrycans');

        $this->actingAs($this->admin)->get(route('expenses.index'))->assertOk()
            ->assertSee(route('expenses.show', $expense), escape: false);
    }

    /**
     * Deleting from the expense's own page cannot go back to it — the row is
     * gone and the route would 404 — so it lands on the list instead.
     */
    public function test_deleting_from_the_expense_page_lands_on_the_list(): void
    {
        $expense = Expense::create([
            'document_no' => app(DocumentNumberService::class)->next(DocumentNumberService::PREFIX_EXPENSE),
            'title' => 'Generator diesel',
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Fuel'])->id,
            'amount' => 25_000, 'expense_date' => now(), 'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->from(route('expenses.show', $expense))
            ->delete(route('expenses.destroy', $expense))
            ->assertRedirect(route('expenses.index'));

        // ...while deleting from the list keeps the reader where they were.
        $other = Expense::create([
            'document_no' => app(DocumentNumberService::class)->next(DocumentNumberService::PREFIX_EXPENSE),
            'title' => 'Tea', 'expense_category_id' => $expense->expense_category_id,
            'amount' => 1_000, 'expense_date' => now(), 'user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->from(route('expenses.index', ['page' => 2]))
            ->delete(route('expenses.destroy', $other))
            ->assertRedirect(route('expenses.index', ['page' => 2]));
    }

    // ---------------------------------------------------------- adjustments

    /**
     * The reason to open an adjustment: an incoming one opens a batch of its
     * own, and that batch is what every later FIFO figure is drawn from.
     */
    public function test_an_incoming_adjustment_shows_the_batch_it_opened(): void
    {
        $adjustment = app(StockAdjustmentService::class)->create(
            product: $this->product, direction: StockAdjustment::DIRECTION_IN,
            quantity: 5, reason: 'miscount', user: $this->admin, unitCost: 9_000,
            notes: 'Found behind the counter',
        );

        $this->actingAs($this->admin)
            ->get(route('stock-adjustments.show', $adjustment))->assertOk()
            ->assertSee($adjustment->document_no)
            ->assertSee($this->product->name)
            ->assertSee('Found behind the counter')
            ->assertSee(__('Batch it opened'))
            ->assertSee('9,000')
            // Five units at 9,000 is what it put into stock.
            ->assertSee('45,000');
    }

    /**
     * An outgoing one has no typed cost: what it wrote off is the FIFO cost of
     * whatever batches it reached, which is only visible in the movements.
     */
    public function test_an_outgoing_adjustment_shows_what_it_wrote_off(): void
    {
        $this->buy();

        $adjustment = app(StockAdjustmentService::class)->create(
            product: $this->product, direction: StockAdjustment::DIRECTION_OUT,
            quantity: 2, reason: 'damage', user: $this->admin,
        );

        $response = $this->actingAs($this->admin)
            ->get(route('stock-adjustments.show', $adjustment))->assertOk();

        $response->assertSee(__('FIFO'));
        $response->assertDontSee(__('Batch it opened'));
        // Two units drawn from the 10,000 batch the purchase opened.
        $response->assertSee('20,000');
    }

    public function test_the_fifo_trail_leads_to_the_adjustment(): void
    {
        $adjustment = app(StockAdjustmentService::class)->create(
            product: $this->product, direction: StockAdjustment::DIRECTION_IN,
            quantity: 5, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );

        $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))->assertOk()
            ->assertSee(route('stock-adjustments.show', $adjustment), escape: false);
    }

    // ----------------------------------------------------------- permissions

    public function test_each_page_needs_its_own_permission(): void
    {
        $purchase = $this->buy(paid: 40_000);
        $payment = Payment::where('payable_id', $purchase->id)->firstOrFail();

        $adjustment = app(StockAdjustmentService::class)->create(
            product: $this->product, direction: StockAdjustment::DIRECTION_IN,
            quantity: 5, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['products.view'])->pluck('id'));

        $this->actingAs($clerk)->get(route('payments.show', $payment))->assertForbidden();
        $this->actingAs($clerk)->get(route('stock-adjustments.show', $adjustment))->assertForbidden();

        // ...and the product page offers them the number without the link.
        $response = $this->actingAs($clerk)->get(route('products.show', $this->product))->assertOk();
        $response->assertSee($adjustment->document_no);
        $this->assertStringNotContainsString(
            'href="'.route('stock-adjustments.show', $adjustment).'"',
            $response->getContent(),
        );
    }
}
