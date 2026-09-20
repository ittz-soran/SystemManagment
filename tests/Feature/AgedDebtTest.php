<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AgedDebtService;
use App\Services\LedgerService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who owes the shop, and how long they have owed it.
 *
 * **Soran, 2026-09-20**, asked for "Advanced Accounting" and chose Profit &
 * Loss plus aged debt. The P&L already existed and was left alone. This is the
 * half that did not.
 *
 * ⚠️ The thing worth testing is not that the arithmetic adds up — it is
 * **which column each debt lands in**, because an off-by-one at a boundary is
 * invisible in every total and wrong on every row. So the ages here sit exactly
 * on 29, 30, 59, 60, 89, 90 and 200 days, and each is asserted into a named
 * bucket rather than into a sum.
 */
class AgedDebtTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770']);

        $this->product = Product::create([
            'name' => 'Charger',
            'kind' => Product::KIND_STOCK,
            'sku' => 'AG-1',
            'barcode' => 'AG-1-B',
            'category_id' => Category::first()->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 2_000,
            'quantity' => 0,
        ]);

        // Stock to sell, paid for in full so it owes nothing of its own.
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 500, 'unit_price' => 1_000]],
            user: $this->user(),
            purchaseDate: now()->subYear(),
            amountPaid: 500_000,
        );
    }

    private function user(): User
    {
        return User::first();
    }

    /** A sale made $daysAgo, paid $paid of its total. */
    private function sell(int $daysAgo, int $total, int $paid = 0): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $total]],
            user: $this->user(),
            saleDate: now()->subDays($daysAgo),
            amountPaid: $paid,
        );
    }

    private function row(array $report)
    {
        return $report['rows']->firstWhere('person.id', $this->customer->id);
    }

    /**
     * ⚠️ THE BOUNDARIES, WHICH ARE THE WHOLE OF IT.
     *
     * 29 days is not yet thirty; 30 days is. An off-by-one here moves money
     * between columns on every row of the report while every total stays
     * exactly right, so nothing else in the system would ever notice.
     */
    public function test_each_debt_lands_in_the_column_its_age_says(): void
    {
        foreach ([[29, 1_000], [30, 2_000], [59, 4_000], [60, 8_000], [89, 16_000], [90, 32_000], [200, 64_000]] as [$days, $amount]) {
            $this->sell($days, $amount);
        }

        $row = $this->row(app(AgedDebtService::class)->receivable());

        $this->assertSame(1_000, $row->buckets[0], 'under 30 days');
        $this->assertSame(2_000 + 4_000, $row->buckets[1], '30 up to 60 days');
        $this->assertSame(8_000 + 16_000, $row->buckets[2], '60 up to 90 days');
        $this->assertSame(32_000 + 64_000, $row->buckets[3], '90 days and older');

        $this->assertSame(127_000, $row->outstanding);
    }

    /** A settled document is not a debt, and neither is an overpaid one. */
    public function test_what_is_paid_for_is_not_owed(): void
    {
        $this->sell(45, 10_000, 10_000);   // paid in full
        $this->sell(45, 10_000, 4_000);    // 6,000 still owed

        $row = $this->row(app(AgedDebtService::class)->receivable());

        $this->assertSame(6_000, $row->outstanding, 'only the unpaid part is a debt');
        $this->assertSame(6_000, $row->buckets[1]);
    }

    /**
     * ⚠️ A return takes the debt down too, and by the APPLIED credit.
     *
     * This is why `amountDue()` is reused rather than re-expressed as a raw
     * aggregate: the credit a return applies is not the same as the return's
     * total, and that distinction has already been a bug once. A second
     * implementation would be free to drift from the first, silently.
     */
    public function test_a_return_reduces_what_is_owed(): void
    {
        $sale = $this->sell(45, 10_000);

        $before = $this->row(app(AgedDebtService::class)->receivable())->outstanding;
        $this->assertSame(10_000, $before);

        $sale->refresh();
        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(),
            returnDate: now(),
        );

        $after = $this->row(app(AgedDebtService::class)->receivable());

        $this->assertSame(
            $sale->fresh()->amountDue(),
            $after?->outstanding ?? 0,
            'the report and the document disagree about what is owed',
        );
    }

    /**
     * ⚠️ The buckets need not equal the balance, and the gap is shown.
     *
     * An opening balance belongs to no document, so it cannot be aged. A report
     * that showed only buckets would quietly under-state what a customer owes.
     * Shown as its own figure, it also makes this a check on the books: any
     * other difference means the documents and the ledger disagree.
     */
    public function test_money_that_belongs_to_no_document_is_reported_rather_than_dropped(): void
    {
        $this->sell(10, 5_000);

        DB::transaction(fn () => app(LedgerService::class)->post(
            account: $this->customer,
            type: AccountTransaction::TYPE_OPENING_BALANCE,
            amount: 7_000,
            reference: null,
            user: $this->user(),
        ));

        $row = $this->row(app(AgedDebtService::class)->receivable());

        $this->assertSame(5_000, $row->outstanding, 'only the sale can be aged');
        $this->assertSame(12_000, $row->balance, 'the ledger holds both');
        $this->assertSame(7_000, $row->unaged, 'and the difference is stated, not hidden');
    }

    /** What the shop owes runs through the same mechanism. */
    public function test_it_ages_what_the_shop_owes_its_suppliers_too(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
            user: $this->user(),
            purchaseDate: now()->subDays(95),
            amountPaid: 2_000,
        );

        $report = app(AgedDebtService::class)->payable();
        $row = $report['rows']->firstWhere('person.id', $this->supplier->id);

        $this->assertSame(8_000, $row->buckets[3], 'over 90 days');
        $this->assertSame(8_000, $report['totals'][3]);
    }

    /** Somebody square with the shop is not on a debt report. */
    public function test_people_who_owe_nothing_are_left_off(): void
    {
        $this->sell(10, 5_000, 5_000);

        $report = app(AgedDebtService::class)->receivable();

        $this->assertNull(
            $report['rows']->firstWhere('person.id', $this->customer->id),
            'a customer who owes nothing was listed anyway',
        );
    }

    /** Read as at a past date, a debt is as old as it was then — and not before it existed. */
    public function test_it_can_be_read_as_at_an_earlier_date(): void
    {
        $this->sell(40, 9_000);

        // Ten days ago that sale was 30 days old, not 40.
        $row = $this->row(app(AgedDebtService::class)->receivable(now()->subDays(10)));
        $this->assertSame(9_000, $row->buckets[1], '30 up to 60 days, as it stood then');

        // And fifty days ago it had not happened.
        $earlier = app(AgedDebtService::class)->receivable(now()->subDays(50));
        $this->assertSame(0, $earlier['outstanding'], 'a sale counted before it was made');
    }
}
