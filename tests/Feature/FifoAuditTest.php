<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockRoom;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\FifoAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replaying the shop's history to find sales that took the wrong layer —
 * Soran, 2026-09-25.
 *
 * ⚠️ This is the audit for a fault that leaves **every screen agreeing**. A
 * sale charged to the wrong batch is self-consistent afterwards: the movement
 * says what it cost, the report sums the movements, the product page reads the
 * same rows. `AccountingAgreesTest` cannot see it, because nothing disagrees.
 * Only walking the history in order can.
 */
class FifoAuditTest extends TestCase
{
    use RefreshDatabase;

    private Product $charger;

    private Supplier $rasan;

    private Customer $karwan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->charger = Product::create([
            'name' => 'Charger 33W', 'kind' => Product::KIND_STOCK, 'sku' => 'CHG-33W',
            'barcode' => 'CHG33W', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 11_000, 'sale_price' => 18_000, 'quantity' => 0,
        ]);

        $this->rasan = Supplier::create(['name' => 'Rasan', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function buy(int $quantity, int $price, int $daysAgo)
    {
        return app(PurchaseService::class)->create(
            supplier: $this->rasan,
            lines: [['product_id' => $this->charger->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $price,
        );
    }

    private function sell(int $quantity)
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->charger->id, 'quantity' => $quantity, 'unit_price' => 18_000]],
            user: $this->user(), saleDate: today(), amountPaid: $quantity * 18_000, paymentMethod: 'cash',
        );
    }

    /** A shop whose FIFO has always worked has nothing to report. */
    public function test_a_shop_in_order_reports_nothing(): void
    {
        $this->buy(5, 11_000, 20);
        $this->buy(5, 12_000, 10);
        $this->sell(3);
        $this->sell(3);

        $this->assertCount(0, (new FifoAudit)->findings(), 'a correct shop must be silent');
    }

    /**
     * ⚠️ **Soran's own fault, reproduced.** MySQL pushed the old batch's
     * `received_at` forward, FIFO reached for the newer layer, and the date was
     * repaired afterwards — leaving a sale in the books that took 12,000 stock
     * while 11,000 stock sat on the shelf.
     */
    public function test_it_finds_a_sale_that_skipped_an_older_layer(): void
    {
        $this->buy(5, 11_000, 20);
        $this->buy(5, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        // The bug: the old layer's date is pushed past the new one's.
        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();

        $this->sell(2);

        // And repaired, the way `stock:repair-batch-dates` repaired the shop.
        $old->forceFill(['received_at' => $wasReceived])->save();

        $findings = (new FifoAudit)->findings();

        $this->assertCount(1, $findings);

        $finding = $findings->first();

        $this->assertSame($this->charger->id, $finding->product_id);
        $this->assertSame(2, $finding->units);
        $this->assertSame($new->id, $finding->took_batch, 'it took the newer layer');
        $this->assertSame($old->id, $finding->older_batch, 'while this one still had stock');
        $this->assertSame(5, $finding->older_left);
        $this->assertSame(2_000, $finding->difference, '2 units, 1,000 dearer each');

        $summary = (new FifoAudit)->summary($findings);

        $this->assertSame(1, $summary['lines']);
        $this->assertSame(2, $summary['units']);
        $this->assertSame(2_000, $summary['difference']);
        $this->assertSame(1, $summary['products']);
    }

    /**
     * ⚠️ **Soran's own sheet, 2026-09-26: the same single unit offered to two
     * sales.** His audit listed INV-00027 and INV-00036 — same product, same
     * day — each as having skipped batch #232, and each said *"1 left"*. One
     * unit cannot be the layer two sales should both have taken: had the first
     * one taken it, the second would have found the layer empty and taken
     * exactly what it did take.
     *
     * The per-line rows are each true — both sales really did pass an
     * available older unit — but the money total added both differences and
     * told him his profit was overstated by twice what it was. A headline
     * figure on an audit is the one number a shopkeeper acts on, so it has to
     * be the honest one: what following FIFO throughout would actually have
     * cost.
     */
    public function test_two_sales_skipping_one_unit_are_not_counted_twice(): void
    {
        // One unit in the old cheap layer, plenty in the new dear one.
        $this->buy(1, 11_000, 20);
        $this->buy(10, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();

        $this->sell(1);
        $this->sell(1);

        $old->forceFill(['received_at' => $wasReceived])->save();

        $audit = new FifoAudit;
        $findings = $audit->findings();

        // Both lines are listed, because both statements are true.
        $this->assertCount(2, $findings, 'both sales did pass an available older unit');

        // ⚠️ But the shop was only ever one unit's difference worse off. The
        // old layer held ONE unit at 1,000 less — that is the whole of it.
        $this->assertSame(
            1_000,
            $audit->summary($findings)['difference'],
            'the same unit was counted against two sales, doubling the headline',
        );
    }

    /**
     * ⚠️ **The alternative history has to run on the lines that were RIGHT
     * too.** A sale that correctly took the oldest layer spends that layer in
     * both worlds — and a ledger that only moves when the audit complains is
     * not an alternative history, it is the same history with holes in it. It
     * would still be holding units a correct sale had already sold, and would
     * then forgive a later line that really did cost the shop money.
     */
    public function test_the_ideal_ledger_spends_on_correct_sales_as_well(): void
    {
        $this->buy(2, 11_000, 20);
        $this->buy(10, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        // One sale while everything is in order: it takes the old layer, and
        // so would FIFO. One of the two cheap units is gone in both worlds.
        $this->sell(1);

        // Then the dates break and two more sales pass the one unit left.
        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();
        $this->sell(1);
        $this->sell(1);
        $old->forceFill(['received_at' => $wasReceived])->save();

        $audit = new FifoAudit;
        $findings = $audit->findings();

        $this->assertCount(2, $findings, 'the correct sale is not a finding');

        // One cheap unit was left to skip, so one unit's 1,000 is the whole
        // loss — not two, and not three.
        $this->assertSame(1_000, $audit->summary($findings)['difference']);
        $this->assertSame(2_000, $audit->summary($findings)['listed'], 'the column still adds to two');
    }

    /** And the sheet says so, rather than leaving him to add the column. */
    public function test_the_sheet_says_why_the_column_does_not_add_to_the_headline(): void
    {
        $this->buy(1, 11_000, 20);
        $this->buy(10, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();
        $this->sell(1);
        $this->sell(1);
        $old->forceFill(['received_at' => $wasReceived])->save();

        $page = $this->actingAs($this->user())->get(route('reports.fifo'))->assertOk();

        $page->assertSee(__('Adding the column below comes to :listed, which is more than the shop lost. Some of these lines passed the SAME units of the same older layer — only the first of them could have taken those units, so the figure above counts them once.', [
            'listed' => money(2_000, false),
        ]));

        // ⚠️ And the table's own foot prints the COLUMN's sum, not the
        // headline — a reader who adds the rows up must land on the figure
        // printed under them, or the sheet has three numbers and no story.
        // Read out of the tfoot itself: both figures appear on this page, so
        // `assertSee` on either one proves nothing about where it is.
        $this->assertMatchesRegularExpression(
            '/<tfoot>.*?'.preg_quote(money(2_000, false), '/').'.*?<\/tfoot>/s',
            $page->getContent(),
            'the table foot does not add up to its own column',
        );
    }

    /** ⚠️ And says nothing when there is nothing to explain. */
    public function test_an_ordinary_finding_gets_no_extra_sentence(): void
    {
        $this->buy(5, 11_000, 20);
        $this->buy(5, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();
        $this->sell(2);
        $old->forceFill(['received_at' => $wasReceived])->save();

        $this->actingAs($this->user())->get(route('reports.fifo'))
            ->assertOk()
            ->assertDontSee('which is more than the shop lost', false);
    }

    /**
     * ⚠️ **A purchase return is not a FIFO fault and must never be reported.**
     * It deducts from the batch that purchase created, by name and on purpose
     * — those goods go back to that supplier, not the oldest ones the shop
     * happens to hold. Auditing it against FIFO would flag every single one.
     */
    public function test_a_supplier_return_off_a_newer_batch_is_not_a_finding(): void
    {
        $this->buy(5, 11_000, 20);
        $newer = $this->buy(5, 12_000, 10);

        app(PurchaseReturnService::class)->create(
            purchase: $newer,
            lines: [['purchase_item_id' => $newer->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user(), returnDate: today(),
        );

        $this->assertCount(0, (new FifoAudit)->findings(),
            'sending goods back to the supplier who sold them is not FIFO going wrong');
    }

    /**
     * ⚠️ A layer in another room was never a candidate — the till sells one
     * room. Auditing across rooms would report a finding on every shop that
     * keeps a second store.
     */
    public function test_an_older_layer_in_another_room_is_not_a_finding(): void
    {
        $this->buy(5, 12_000, 10);

        $back = StockRoom::create([
            'name' => 'Back room', 'is_main' => false, 'is_active' => true,
        ]);

        // An older, cheaper layer that the till cannot reach.
        StockBatch::create([
            'product_id' => $this->charger->id,
            'room_id' => $back->id,
            'source_type' => 'adjustment',
            'source_id' => 1,
            'unit_cost' => 9_000,
            'quantity_in' => 10,
            'quantity_remaining' => 10,
            'received_at' => now()->subDays(60),
            'sequence' => 1,
        ]);

        $this->sell(2);

        $this->assertCount(0, (new FifoAudit)->findings());
    }

    /**
     * ⚠️ **Replayed in the order things HAPPENED, not the order they were
     * typed.** A shop catching up on a week of paper enters Thursday after
     * Friday, and the two orders give different answers — so this builds a
     * history where they disagree and pins the audit to the dates.
     *
     * Bought two cheap, then five dear. A sale dated two days ago is entered
     * first and empties the cheap layer; a sale dated five days ago is entered
     * afterwards and can only reach the dear one. Read by id nothing is odd.
     * Read by date — which is how the books will be read, and how FIFO is
     * meant to run — the earlier sale took 12,000 stock with 11,000 stock on
     * the shelf, and that is worth telling the shopkeeper about.
     */
    public function test_it_replays_by_when_things_happened_not_by_when_they_were_typed(): void
    {
        $this->buy(2, 11_000, 20);
        $this->buy(5, 12_000, 10);

        $late = fn (int $quantity, int $daysAgo) => app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->charger->id, 'quantity' => $quantity, 'unit_price' => 18_000]],
            user: $this->user(), saleDate: today()->subDays($daysAgo),
            amountPaid: $quantity * 18_000, paymentMethod: 'cash',
        );

        // Typed in this order; dated the other way round.
        $late(2, 2);
        $late(2, 5);

        $findings = (new FifoAudit)->findings();

        $this->assertCount(1, $findings, 'read in date order, the earlier sale skipped the cheap layer');

        $finding = $findings->first();

        $this->assertSame(12_000, $finding->took_cost);
        $this->assertSame(11_000, $finding->older_cost);
        $this->assertSame(2_000, $finding->difference);
        $this->assertTrue(
            $finding->occurred_at->isSameDay(today()->subDays(5)),
            'the finding is against the earlier-DATED sale, whichever was typed first',
        );
    }

    /**
     * ⚠️ Rendered, because a printed report that dies on a Blade trap passes
     * every test that only ever asks the engine for numbers.
     */
    public function test_the_printed_sheet_opens_with_and_without_findings(): void
    {
        $this->buy(5, 11_000, 20);
        $this->buy(5, 12_000, 10);
        $this->sell(2);

        $this->actingAs($this->user())->get(route('reports.fifo'))
            ->assertOk()->assertSee('Nothing to report');

        // Now break it the way MySQL did, and open the sheet again.
        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();
        $this->sell(2);
        $old->forceFill(['received_at' => $wasReceived])->save();

        $sheet = $this->actingAs($this->user())->get(route('reports.fifo'));

        $sheet->assertOk()
            ->assertSee('took the wrong layer')
            ->assertDontSee('Nothing to report');

        // ⚠️ The number printed on the paper, not the row id. A sheet saying
        // "SALE #9" sends the shopkeeper looking for which invoice that is.
        $sheet->assertSee(Sale::latest('id')->firstOrFail()->document_no)
            ->assertDontSee('SALE #');
    }

    /** The window narrows the answer without changing what counts as one. */
    public function test_the_audit_can_be_asked_about_one_period(): void
    {
        $this->buy(5, 11_000, 20);
        $this->buy(5, 12_000, 10);

        $old = StockBatch::where('unit_cost', 11_000)->firstOrFail();
        $new = StockBatch::where('unit_cost', 12_000)->firstOrFail();
        $wasReceived = $old->received_at;

        $old->forceFill(['received_at' => $new->received_at->copy()->addHour()])->save();
        $this->sell(1);
        $old->forceFill(['received_at' => $wasReceived])->save();

        $audit = new FifoAudit;

        $this->assertCount(1, $audit->findings(today()->startOfDay(), today()->endOfDay()));
        $this->assertCount(0, $audit->findings(today()->subDays(9), today()->subDays(5)));
    }
}
