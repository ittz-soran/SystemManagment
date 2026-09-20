<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The workshop book — Soran, 2026-09-20.
 *
 * *Services* was already there and is often mistaken for this: it is a price
 * line added to a sale. It records the money and nothing about the job — whose
 * phone, what is wrong, what it looked like on arrival, or which stage it is
 * at. Those lived on paper.
 *
 * ⚠️ **What these tests are really holding is that a repair owns no stock and
 * no money.** A job keeps its parts as lines and leaves them on the shelf;
 * collecting makes an ordinary `Sale`, and that sale is the single thing that
 * consumes FIFO, charges the cost and takes the payment. The alternative — stock
 * moving when a part is fitted — would be a second implementation of Section 5,
 * which is where the worst bugs in this system have come from.
 *
 * So the defining assertion is a negative one: while a job sits on the bench,
 * nothing anywhere moved.
 */
class RepairTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $part;

    private Product $labour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $this->part = Product::create([
            'name' => 'iPhone 12 screen',
            'kind' => Product::KIND_STOCK,
            'sku' => 'RP-1',
            'barcode' => 'RP-1-B',
            'category_id' => Category::first()->id,
            'unit' => 'pcs',
            'purchase_price' => 20_000,
            'sale_price' => 35_000,
            'quantity' => 0,
        ]);

        $this->labour = Product::create([
            'name' => 'Screen fitting',
            'kind' => Product::KIND_SERVICE,
            'sku' => 'RP-S',
            'barcode' => 'RP-S-B',
            'category_id' => Category::first()->id,
            'unit' => 'pcs',
            'purchase_price' => 0,
            'sale_price' => 25_000,
            'quantity' => 0,
        ]);

        // Two batches at different costs, so FIFO has something to prove.
        foreach ([[3, 20_000], [3, 24_000]] as [$quantity, $cost]) {
            app(PurchaseService::class)->create(
                supplier: Supplier::firstOrCreate(['name' => 'Bazaar Mobile'], ['phone' => '0770']),
                lines: [['product_id' => $this->part->id, 'quantity' => $quantity, 'unit_price' => $cost]],
                user: $this->user(),
                purchaseDate: now()->subMonth(),
                amountPaid: $quantity * $cost,
            );
        }
    }

    private function user(): User
    {
        return User::first();
    }

    private function takeIn(array $lines = []): Repair
    {
        return app(RepairService::class)->create(
            customer: $this->customer,
            device: 'iPhone 12 Pro, blue',
            fault: 'Screen cracked, touch dead along the bottom',
            user: $this->user(),
            identifier: '355123456789012',
            conditionNote: 'Back glass already cracked, no charger with it',
            promisedFor: now()->addDays(2),
            estimate: 60_000,
            lines: $lines ?: [
                ['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000],
                ['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 25_000],
            ],
        );
    }

    /**
     * ⚠️ THE ONE THAT DEFINES THE MODULE.
     *
     * A job on the bench has moved nothing: not the counted quantity, not a
     * batch, not a movement row, not a dinar. The part is listed against the
     * job and still on the shelf.
     */
    public function test_a_job_on_the_bench_moves_no_stock_and_no_money(): void
    {
        $quantityBefore = $this->part->fresh()->quantity;
        $movementsBefore = StockMovement::count();

        $repair = $this->takeIn();

        $this->assertSame($quantityBefore, $this->part->fresh()->quantity, 'taking a repair in moved stock');
        $this->assertSame($movementsBefore, StockMovement::count(), 'taking a repair in wrote a stock movement');
        $this->assertNull($repair->sale_id, 'taking a repair in made a sale');
        $this->assertSame(0, $this->customer->fresh()->balance, 'taking a repair in charged the customer');

        // And the parts are recorded against it, which is where the truth about
        // what is committed lives.
        $this->assertSame(60_000, $repair->total());
    }

    /** Collecting is the moment everything happens, and it happens once. */
    public function test_collecting_makes_one_ordinary_sale_at_the_fifo_cost(): void
    {
        $repair = $this->takeIn();
        $quantityBefore = $this->part->fresh()->quantity;

        $sale = app(RepairService::class)->collect($repair, $this->user(), amountPaid: 60_000);

        $this->assertSame(60_000, $sale->total_amount);
        $this->assertSame(0, $sale->amountDue());
        $this->assertSame($quantityBefore - 1, $this->part->fresh()->quantity, 'the part came off the shelf exactly once');

        // FIFO took the older batch, at 20,000 — not the average and not the
        // product's purchase_price.
        $cost = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('-'.StockMovement::VALUE));

        $this->assertSame(20_000, $cost, 'the repair was costed at something other than the oldest batch');

        // The service line is not stock and must not pretend to be.
        $this->assertSame(1, StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)->count(), 'the labour line moved stock');
    }

    /**
     * ⚠️ The link to the invoice, which was silently missing.
     *
     * `sale_id` is deliberately not fillable — it is the system's link, never
     * something a form may set — and `update()` drops what it may not write
     * without saying so. The job came out `collected` with no sale against it:
     * a repair nobody could trace to the money that paid for it.
     */
    public function test_a_collected_job_knows_which_invoice_paid_for_it(): void
    {
        $repair = $this->takeIn();

        $sale = app(RepairService::class)->collect($repair, $this->user(), amountPaid: 60_000);

        $repair = $repair->fresh();

        $this->assertSame(Repair::STATUS_COLLECTED, $repair->status);
        $this->assertSame($sale->id, $repair->sale_id, 'the job was collected without recording its sale');
        $this->assertSame($sale->document_no, $repair->sale->document_no);
    }

    /**
     * ⚠️ A job cannot be marked collected without being paid for.
     *
     * Collection is a sale, not a status. Letting it through the status setter
     * would leave a job reading `collected` with no invoice, no stock movement
     * and no money — the one state this module must not be able to reach.
     */
    public function test_a_job_cannot_be_marked_collected_without_a_sale(): void
    {
        $repair = $this->takeIn();

        $this->expectException(RuntimeException::class);

        app(RepairService::class)->setStatus($repair, Repair::STATUS_COLLECTED, $this->user());
    }

    /** And a job with nothing on it cannot be collected either. */
    public function test_an_empty_job_cannot_be_collected(): void
    {
        $repair = app(RepairService::class)->create(
            customer: $this->customer,
            device: 'Nokia 3310',
            fault: 'Will not charge',
            user: $this->user(),
        );

        $quantityBefore = $this->part->fresh()->quantity;

        try {
            app(RepairService::class)->collect($repair, $this->user());
            $this->fail('an empty repair was collected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paying for', $e->getMessage());
        }

        $this->assertSame($quantityBefore, $this->part->fresh()->quantity);
        $this->assertNull($repair->fresh()->sale_id);
    }

    /** Collected once, and only once. */
    public function test_a_job_cannot_be_collected_twice(): void
    {
        $repair = $this->takeIn();

        app(RepairService::class)->collect($repair, $this->user(), amountPaid: 60_000);

        $quantityAfterFirst = $this->part->fresh()->quantity;

        try {
            app(RepairService::class)->collect($repair->fresh(), $this->user(), amountPaid: 60_000);
            $this->fail('the same repair was collected twice');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already been collected', $e->getMessage());
        }

        $this->assertSame($quantityAfterFirst, $this->part->fresh()->quantity, 'a second collection took the part again');
    }

    /** A collected job is locked, and the refusal names the invoice to delete. */
    public function test_a_collected_job_is_locked_and_says_which_invoice_holds_it(): void
    {
        $repair = $this->takeIn();
        $sale = app(RepairService::class)->collect($repair, $this->user(), amountPaid: 60_000);

        $repair = $repair->fresh();
        $this->assertFalse($repair->canBeModified());

        try {
            app(RepairService::class)->setStatus($repair, Repair::STATUS_READY, $this->user());
            $this->fail('a collected repair was edited');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($sale->document_no, $e->getMessage(),
                'the refusal did not name the invoice standing in the way');
        }
    }

    /**
     * Handed back unmended: an outcome, not a mistake.
     *
     * A phone that cannot be saved, or whose owner will not pay what it would
     * cost, still leaves the shop — and still has to stop appearing on the
     * bench. No sale, because nothing was sold.
     */
    public function test_a_job_handed_back_unrepaired_charges_nothing(): void
    {
        $repair = $this->takeIn();
        $quantityBefore = $this->part->fresh()->quantity;

        app(RepairService::class)->handBack($repair, $this->user(), 'Board is water damaged, not worth it');

        $repair = $repair->fresh();

        $this->assertSame(Repair::STATUS_RETURNED, $repair->status);
        $this->assertNull($repair->sale_id);
        $this->assertSame($quantityBefore, $this->part->fresh()->quantity);
        $this->assertSame(0, $this->customer->fresh()->balance);
        $this->assertFalse($repair->isOpen(), 'a phone handed back is still sitting on the bench');
    }

    /**
     * ⚠️ The field that stops an argument.
     *
     * What the phone looked like on arrival is the whole reason a shop writes a
     * ticket. It is easy to treat as an optional note and drop in a refactor,
     * so it is asserted like a figure.
     */
    public function test_what_it_looked_like_on_arrival_is_kept(): void
    {
        $repair = $this->takeIn();

        $this->assertSame('Back glass already cracked, no charger with it', $repair->condition_note);
        $this->assertSame('355123456789012', $repair->identifier);
        $this->assertSame('iPhone 12 Pro, blue', $repair->device);
    }

    /** Section 7b: its own counter, its own prefix. */
    public function test_each_job_takes_the_next_ticket_number(): void
    {
        $first = $this->takeIn();
        $second = $this->takeIn();

        $this->assertSame('REP-00001', $first->document_no);
        $this->assertSame('REP-00002', $second->document_no);
    }

    /**
     * ⚠️ A walk-in must pay before the phone leaves, and nothing here had to
     * say so.
     *
     * The rule lives on the sale — the Cash Customer's balance may not go
     * negative — and collection goes through `SaleService`, so a repair
     * inherits it. That inheritance is the argument for the whole design, so it
     * is worth a test of its own: if collection ever stopped going through the
     * sale, this is what would notice.
     */
    public function test_a_walk_in_cannot_take_the_phone_away_owing_money(): void
    {
        $repair = app(RepairService::class)->create(
            customer: Customer::where('is_system', true)->firstOrFail(),
            device: 'Redmi Note 11',
            fault: 'Charging port loose',
            user: $this->user(),
            lines: [['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 15_000]],
        );

        try {
            app(RepairService::class)->collect($repair, $this->user(), amountPaid: 5_000);
            $this->fail('a walk-in took the phone away owing money');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('full', $e->getMessage());
        }

        $this->assertNull($repair->fresh()->sale_id);
    }

    /** Overdue is promised, not done, and in the past — a collected job is never late. */
    public function test_only_an_unfinished_job_can_be_late(): void
    {
        $late = app(RepairService::class)->create(
            customer: $this->customer,
            device: 'iPad',
            fault: 'Cracked',
            user: $this->user(),
            promisedFor: now()->subDays(3),
            lines: [['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 10_000]],
        );

        $this->assertTrue($late->isOverdue());

        app(RepairService::class)->collect($late, $this->user(), amountPaid: 10_000);

        $this->assertFalse($late->fresh()->isOverdue(), 'a finished job was still counted as late');
    }
}
