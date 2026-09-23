<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The device has been here before — Soran, 2026-09-23.
 *
 * *"add warranty warning when device come back"*. The warranty was printed on
 * the ticket and on the job, and nothing ever said a word when the device came
 * back through the door: the shop had to remember, or search the IMEI with the
 * status filter set to Collected and read the dates itself.
 *
 * ⚠️ A shop that forgets charges a customer twice for the same screen, which is
 * the argument this whole module exists to prevent.
 *
 * ⚠️ **The two tests that RENDER the job screen are also what guards the
 * warning against Blade eating it.** A Blade comment naming the `@php`
 * directive is paired with the real `@endphp` below it, and the whole warning
 * block disappears from the page — silently, with nothing thrown. A test that
 * inspected `Blade::compileString()` instead would not catch it: Blade
 * restores the stored block into the compiled output, so the text is all still
 * there, merely in the wrong place. Rendering is the only thing that knows the
 * difference, and it answers 500.
 */
class RepairWarrantyReturnTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $screen;

    private Product $labour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        // A screen carries five days; labour, thirty.
        $this->screen = $this->product('iPhone 12 screen', 'W-SCR', Product::KIND_STOCK, 20_000, 35_000, 5);
        $this->labour = $this->product('Screen fitting', 'W-LAB', Product::KIND_SERVICE, 0, 15_000, 30);

        app(PurchaseService::class)->create(
            supplier: Supplier::firstOrCreate(['name' => 'Bazaar'], ['phone' => '0770']),
            lines: [['product_id' => $this->screen->id, 'quantity' => 5, 'unit_price' => 20_000]],
            user: $this->user(), purchaseDate: now()->subMonth(), amountPaid: 100_000,
        );
    }

    private function product(string $name, string $sku, string $kind, int $cost, int $price, ?int $days): Product
    {
        return Product::create([
            'name' => $name, 'kind' => $kind, 'sku' => $sku, 'barcode' => $sku.'-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => $cost, 'sale_price' => $price, 'quantity' => 0,
            'warranty_days' => $days,
        ]);
    }

    private function user(): User
    {
        return User::first();
    }

    /** A job taken in, agreed and collected, `$daysAgo` days ago. */
    private function collectedJob(string $identifier, int $daysAgo): Repair
    {
        $repair = app(RepairService::class)->create(
            customer: $this->customer, device: 'iPhone 12 Pro', fault: 'Screen cracked',
            user: $this->user(), receivedAt: now()->subDays($daysAgo + 1), identifier: $identifier,
            lines: [
                ['product_id' => $this->screen->id, 'quantity' => 1, 'unit_price' => 35_000],
                ['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 15_000],
            ],
        );

        app(RepairService::class)->accept($repair, $this->user());
        app(RepairService::class)->collect($repair->fresh('items'), $this->user(),
            amountPaid: 50_000, collectedAt: now()->subDays($daysAgo));

        return $repair->fresh();
    }

    /** ⚠️ Inside the five days, the screen is still covered and the job says so. */
    public function test_a_device_back_inside_the_warranty_is_flagged_on_the_job(): void
    {
        $first = $this->collectedJob('355123456789012', daysAgo: 3);

        $second = app(RepairService::class)->create(
            customer: $this->customer, device: 'iPhone 12 Pro',
            fault: 'Touch dead along the bottom again', user: $this->user(),
            identifier: '355123456789012',
        );

        $history = app(RepairService::class)->historyFor($second->identifier, $second->id);

        $this->assertCount(1, $history);
        $this->assertSame($first->document_no, $history->first()->repair->document_no);
        $this->assertTrue(app(RepairService::class)->stillCovered($history));

        $page = $this->actingAs($this->user())->get(route('repairs.show', $second));

        $page->assertOk();
        $page->assertSee(__('This device is still under warranty from an earlier repair'));
        $page->assertSee($first->document_no);
    }

    /**
     * ⚠️ Past the five days the screen is out, but the thirty-day labour is not.
     *
     * Cover is per line, not per job — that is the whole reason the warranty is
     * copied onto each line rather than kept once on the ticket.
     */
    public function test_cover_runs_out_line_by_line_not_job_by_job(): void
    {
        $this->collectedJob('355123456789012', daysAgo: 10);

        $history = app(RepairService::class)->historyFor('355123456789012');

        $lines = $history->first()->lines->keyBy('name');

        $this->assertFalse($lines['iPhone 12 screen']->covered, 'a 5-day screen is still covered after 10 days');
        $this->assertTrue($lines['Screen fitting']->covered, 'a 30-day job ran out after 10 days');
        $this->assertTrue(app(RepairService::class)->stillCovered($history));
    }

    /** Everything expired still says the device was here — it ends an argument too. */
    public function test_a_device_out_of_warranty_is_still_reported_as_seen_before(): void
    {
        $first = $this->collectedJob('355123456789012', daysAgo: 60);

        $second = app(RepairService::class)->create(
            customer: $this->customer, device: 'iPhone 12 Pro', fault: 'Cracked again',
            user: $this->user(), identifier: '355123456789012',
        );

        $history = app(RepairService::class)->historyFor($second->identifier, $second->id);

        $this->assertCount(1, $history);
        $this->assertFalse(app(RepairService::class)->stillCovered($history));

        $page = $this->actingAs($this->user())->get(route('repairs.show', $second));

        $page->assertOk();
        $page->assertSee(__('This device has been here before'));
        $page->assertDontSee(__('This device is still under warranty from an earlier repair'));
        $page->assertSee($first->document_no);
    }

    /**
     * ⚠️ Matched trimmed and without case. A serial typed in lower case on
     * Tuesday is the same device as the one typed in capitals on Monday.
     */
    public function test_the_identifier_is_matched_loosely_enough_to_be_useful(): void
    {
        $this->collectedJob('SN-DLX9921', daysAgo: 1);

        foreach (['sn-dlx9921', '  SN-DLX9921  ', 'Sn-Dlx9921'] as $typed) {
            $this->assertCount(1, app(RepairService::class)->historyFor($typed), "missed '{$typed}'");
        }

        $this->assertCount(0, app(RepairService::class)->historyFor('SN-OTHER'));
    }

    /**
     * ⚠️ An empty identifier matches NOTHING.
     *
     * Most jobs carry none — a television with the label worn off, a console
     * nobody wrote the serial of — and matching on empty would report every one
     * of them as the same device, on every screen, for ever.
     */
    public function test_a_device_with_no_identifier_matches_nothing(): void
    {
        /*
         * ⚠️ Two of them, and the EMPTY STRING one is the test.
         *
         * A null identifier is refused by the database comparison anyway —
         * `TRIM(NULL) = ''` is never true — so a fixture with only nulls passes
         * whether the guard is there or not, and this test said nothing when
         * the guard was taken out. The form stores `''` for a box left blank,
         * and that is the value that matches every other blank job.
         */
        foreach ([null, ''] as $blank) {
            $repair = app(RepairService::class)->create(
                customer: $this->customer, device: 'Some TV', fault: 'No picture',
                user: $this->user(), identifier: $blank,
                lines: [['product_id' => $this->labour->id, 'quantity' => 1, 'unit_price' => 15_000]],
            );

            app(RepairService::class)->accept($repair, $this->user());
            app(RepairService::class)->collect($repair->fresh('items'), $this->user());
        }

        $this->assertSame(2, Repair::where('status', Repair::STATUS_COLLECTED)->count());

        foreach ([null, '', '   '] as $nothing) {
            $this->assertCount(0, app(RepairService::class)->historyFor($nothing),
                'a blank identifier matched other blank jobs');
        }
    }

    /** A job still on the bench is this visit, not a previous one. */
    public function test_an_uncollected_job_is_not_a_previous_visit(): void
    {
        app(RepairService::class)->create(
            customer: $this->customer, device: 'iPhone 12 Pro', fault: 'Cracked',
            user: $this->user(), identifier: '355123456789012',
            lines: [['product_id' => $this->screen->id, 'quantity' => 1, 'unit_price' => 35_000]],
        );

        $this->assertCount(0, app(RepairService::class)->historyFor('355123456789012'));
    }

    /** The take-in form answers the same question as the number is typed. */
    public function test_the_form_can_ask_whether_the_device_has_been_here(): void
    {
        $first = $this->collectedJob('355123456789012', daysAgo: 2);

        $this->actingAs($this->user())
            ->getJson(route('repairs.history', ['identifier' => ' 355123456789012 ']))
            ->assertOk()
            ->assertJsonPath('covered', true)
            ->assertJsonPath('visits.0.number', $first->document_no)
            ->assertJsonPath('visits.0.lines.0.covered', true);

        $this->actingAs($this->user())
            ->getJson(route('repairs.history', ['identifier' => 'never-seen']))
            ->assertOk()
            ->assertJsonPath('covered', false)
            ->assertJsonCount(0, 'visits');
    }
}
