<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RepairService;
use App\Services\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a repair costs the shop, and who earned it — Soran, 2026-09-22.
 *
 * *"see both sale price and cost of same batch by permission"* and *"monthly or
 * weekly show data statistics and how many tacked jobs and profits"*.
 *
 * ⚠️ **The defining property here is that a job's cost and the shop's cost are
 * the same number.** Before collection nothing has moved, so the figure is a
 * forecast read off the FIFO queue; after collection it is read from the very
 * `stock_movements` Profit & Loss adds up. A second opinion about cost — a
 * product's list price, an average, anything convenient — would let a job's
 * profit and the shop's profit disagree, and there is no version of that where
 * the shop is not being lied to by one of the two screens.
 */
class RepairCostTest extends TestCase
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
            'sku' => 'RC-1',
            'barcode' => 'RC-1-B',
            'category_id' => Category::first()->id,
            'unit' => 'pcs',
            'purchase_price' => 20_000,
            'sale_price' => 35_000,
            'quantity' => 0,
            'warranty_days' => 5,
        ]);

        $this->labour = Product::create([
            'name' => 'Screen fitting',
            'kind' => Product::KIND_SERVICE,
            'sku' => 'RC-S',
            'barcode' => 'RC-S-B',
            'category_id' => Category::first()->id,
            'unit' => 'pcs',
            'purchase_price' => 0,
            'sale_price' => 25_000,
            'quantity' => 0,
        ]);

        // ⚠️ Three at 20,000 and three at 24,000. The whole point of the
        // fixture: any figure that comes out a flat multiple of one price has
        // not been through FIFO.
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

    private function technician(string $name = 'Rebin Aziz', string $email = 'rebin@example.com'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'phone' => '0751 220 4411',
            'password' => 'x',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $user->permissions()->attach(
            Permission::whereIn('key', ['auth.login', 'repairs.view', 'repairs.edit'])->pluck('id')
        );

        return $user;
    }

    /** @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $lines */
    private function takeIn(array $lines): Repair
    {
        return app(RepairService::class)->create(
            customer: $this->customer,
            device: 'PlayStation 5',
            fault: 'Will not read a disc',
            user: $this->user(),
            lines: $lines,
        );
    }

    /**
     * ⚠️ The forecast walks the batch queue. It is NOT quantity × list price.
     *
     * Four screens are 3 at 20,000 and 1 at 24,000 — 84,000. A shop reading
     * 80,000 off the product's own `purchase_price` would think it had made
     * 4,000 more than it has, every time, on the one part it sells most.
     */
    public function test_an_uncollected_job_is_costed_off_the_fifo_queue(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 4, 'unit_price' => 35_000],
        ]);

        $cost = app(RepairService::class)->costOf($repair);

        $this->assertSame(84_000, $cost['cost'], 'the forecast is not FIFO');
        $this->assertFalse($cost['real'], 'a job on the bench is being reported as settled');
        $this->assertSame(0, $cost['short']);
        $this->assertNotSame(80_000, $cost['cost'], 'the forecast used the list price');
    }

    /**
     * ⚠️ Two lines for the same part must not both be priced from the oldest
     * batch.
     *
     * FIFO gives the first line the cheap layer and the second whatever is
     * left. Costing each line from a fresh queue makes a job needing two of
     * something quietly too cheap — and it is cheapest exactly when the shop is
     * about to run out, which is when the number matters most.
     */
    public function test_two_lines_of_one_part_do_not_both_take_the_oldest_batch(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 2, 'unit_price' => 35_000],
            ['product_id' => $this->part->id, 'quantity' => 2, 'unit_price' => 35_000],
        ]);

        $cost = app(RepairService::class)->costOf($repair);

        $this->assertSame(84_000, $cost['cost'], 'both lines drew from the oldest batch');

        [$first, $second] = $repair->items->values()->all();

        $this->assertSame(40_000, $cost['lines'][$first->id]);
        $this->assertSame(44_000, $cost['lines'][$second->id], 'the second line did not take the dearer layer');
    }

    /** A part still on order is counted at what it last cost, and SAID to be missing. */
    public function test_a_part_not_in_stock_yet_is_flagged_rather_than_guessed_at_silently(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 8, 'unit_price' => 35_000],
        ]);

        $cost = app(RepairService::class)->costOf($repair);

        /*
         * 3 × 20,000 + 3 × 24,000 on the shelf is 132,000, then 2 more at what
         * the screen last cost — which is 24,000, not the 20,000 it was first
         * bought at, because a purchase writes the price it was bought at back
         * onto the product. What the shop would pay for the next one is the
         * only honest guess available for one it has not bought yet.
         */
        $this->assertSame(180_000, $cost['cost']);
        $this->assertSame(2, $cost['short'], 'the shop is not told the figure is incomplete');
    }

    /** Labour is not a part. Costing it off an empty queue would report it as on order. */
    public function test_a_service_line_costs_nothing_and_is_not_reported_as_missing(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 25_000],
        ]);

        $cost = app(RepairService::class)->costOf($repair);

        $this->assertSame(0, $cost['cost']);
        $this->assertSame(0, $cost['short'], 'labour was reported as a part nobody has ordered');
    }

    /**
     * ⚠️ **The one that matters: once collected, a job's cost IS the shop's cost.**
     *
     * Read from the movements the sale wrote, which is the same place Profit &
     * Loss reads. If these two ever disagree, one of the two screens is lying
     * to the shop about its own money.
     */
    public function test_a_collected_job_is_costed_from_the_movements_the_sale_wrote(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 4, 'unit_price' => 35_000],
            ['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 25_000],
        ]);

        app(RepairService::class)->accept($repair, $this->user());
        $sale = app(RepairService::class)->collect($repair->fresh('items'), $this->user());

        $cost = app(RepairService::class)->costOf($repair->fresh());

        $fromTheBooks = (int) -StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->selectRaw('SUM(quantity * unit_cost) as c')
            ->value('c');

        $this->assertTrue($cost['real'], 'a collected job is still being forecast');
        $this->assertSame($fromTheBooks, $cost['cost'], 'the job and the books disagree about cost');
        $this->assertSame(84_000, $cost['cost']);
    }

    /**
     * Lines are matched to the sale by POSITION, not by product.
     *
     * Two lines of the same screen would be indistinguishable by product, and
     * guessing would put the cheap layer on whichever row was read first.
     */
    public function test_line_costs_survive_collection_in_the_right_order(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 3, 'unit_price' => 35_000],
            ['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000],
        ]);

        app(RepairService::class)->accept($repair, $this->user());
        app(RepairService::class)->collect($repair->fresh('items'), $this->user());

        $repair = $repair->fresh('items');
        $cost = app(RepairService::class)->costOf($repair);

        [$first, $second] = $repair->items->values()->all();

        $this->assertSame(60_000, $cost['lines'][$first->id], 'the first line did not take the cheap layer');
        $this->assertSame(24_000, $cost['lines'][$second->id], 'the second line did not take the dearer one');
    }

    /**
     * ⚠️ What a repair person sees of cost is the setting they already have.
     *
     * Soran: *"by permission like other system users are can see real cost or
     * increase price by percentage"*. No second rule for repairs — and the
     * profit beside the cost is worked out from the MASKED figure, or the real
     * one is a single subtraction away.
     */
    public function test_a_marked_up_reader_sees_a_marked_up_cost_and_a_profit_that_matches_it(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000],
        ]);

        $reader = $this->technician();
        $reader->forceFill(['cost_visibility' => User::COST_MARKUP, 'cost_markup_percent' => 20])->save();

        $page = $this->actingAs($reader)->get(route('repairs.show', $repair));

        $page->assertOk();

        // 20,000 real, shown as 24,000; charged 35,000, so 11,000 and not 15,000.
        $page->assertSee('24,000');
        $page->assertSee('11,000');
        $page->assertDontSee('15,000');
    }

    /** Somebody shown no cost is shown no profit either, not a profit of zero. */
    public function test_a_reader_shown_no_cost_is_shown_no_profit(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000],
        ]);

        $reader = $this->technician();
        $reader->forceFill(['cost_visibility' => User::COST_HIDDEN])->save();

        $page = $this->actingAs($reader)->get(route('repairs.show', $repair));

        $page->assertOk();
        $page->assertDontSee(__('Profit on this job'));
    }

    /**
     * ⚠️ Only somebody who may work on repairs can be handed one.
     *
     * The list on the screen and the rule the form is checked against are the
     * same query, so a posted id for the shop's accountant is refused rather
     * than quietly accepted because it exists in `users`.
     */
    public function test_a_job_cannot_be_given_to_somebody_who_does_not_work_on_repairs(): void
    {
        $repair = $this->takeIn([
            ['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000],
        ]);

        $counter = User::create([
            'name' => 'Hawkar at the till', 'email' => 'hawkar@example.com',
            'password' => 'x', 'role' => User::ROLE_USER, 'is_active' => true,
        ]);
        $counter->permissions()->attach(Permission::where('key', 'sales.create')->value('id'));

        $this->actingAs($this->user())
            ->post(route('repairs.accept', $repair), [
                'technician_id' => $counter->id,
                'channel' => 'counter',
            ])
            ->assertSessionHasErrors('technician_id');

        $this->assertNull($repair->fresh()->technician_id);
    }

    /**
     * The page Soran asked for: jobs and profit, per person, over a period.
     *
     * ⚠️ Money is counted against the period the job was COLLECTED in, because
     * that is when the sale happens — and a refund is netted off whenever it
     * happened, since a person's profit must never include money the shop has
     * given back.
     */
    public function test_the_report_counts_each_person_and_nets_off_a_refund(): void
    {
        $rebin = $this->technician();
        $hama = $this->technician('Hama Salih', 'hama@example.com');

        // Rebin: one screen, collected. 35,000 charged against 20,000 of cost.
        $one = $this->takeIn([['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000]]);
        app(RepairService::class)->accept($one, $this->user(), technician: $rebin);
        app(RepairService::class)->collect($one->fresh('items'), $this->user());

        // Hama: one screen, collected, and then the customer brings it back.
        $two = $this->takeIn([['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000]]);
        app(RepairService::class)->accept($two, $this->user(), technician: $hama);
        $sale = app(RepairService::class)->collect($two->fresh('items'), $this->user());

        app(SaleReturnService::class)->create(
            sale: Sale::find($sale->id),
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(),
            returnDate: now(),
        );

        // Hama also has one still on the bench, which is not period money.
        $three = $this->takeIn([['product_id' => $this->part->id, 'quantity' => 1, 'unit_price' => 35_000]]);
        app(RepairService::class)->accept($three, $this->user(), technician: $hama);

        $page = $this->actingAs($this->user())->get(route('reports.technicians'));

        $page->assertOk();
        $page->assertSee('Rebin Aziz');
        $page->assertSee('Hama Salih');

        // Rebin: charged 35,000, cost 20,000, profit 15,000.
        $page->assertSee('15,000');

        /*
         * ⚠️ Hama's refunded job nets to nothing, so he must not appear to have
         * earned the shop 15,000 on money it handed straight back. His profit
         * is 0 and the page total is Rebin's 15,000 alone.
         */
        $rows = $page->viewData('people')->keyBy(fn ($row) => $row->person->name);

        $this->assertSame(15_000, $rows['Rebin Aziz']->profit);
        $this->assertSame(0, $rows['Hama Salih']->profit, 'a refunded job still counts as profit');
        $this->assertSame(1, $rows['Hama Salih']->onBench, 'the unfinished job is not on the bench');
        $this->assertSame(2, $rows['Hama Salih']->takenIn);
    }
}
