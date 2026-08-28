<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a stock adjustment that was written down wrong.
 *
 * Section 8's shape for an edit: reverse the document and apply it again, never
 * work from the difference. Counting five and writing three is not "put two
 * back" — it is the original undone and a new one of three in its place, so the
 * batches end up exactly as they would have if it had been right the first time.
 */
class AdjustmentEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

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
    }

    private function adjust(string $direction, int $quantity, ?int $cost = null): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(
            product: $this->product, direction: $direction, quantity: $quantity,
            reason: 'miscount', user: $this->admin, unitCost: $cost,
        );
    }

    public function test_correcting_an_incoming_count_rewrites_its_batch(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);
        $this->assertSame(5, $this->product->refresh()->quantity);

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 3, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );

        $this->assertSame(3, $this->product->refresh()->quantity);

        $batches = StockBatch::where('product_id', $this->product->id)->get();

        $this->assertCount(1, $batches, 'One layer, rewritten — not the old one plus a correction');
        $this->assertSame(3, (int) $batches->first()->quantity_in);
        $this->assertSame(3, (int) $batches->first()->quantity_remaining);
    }

    public function test_the_cost_can_be_corrected_too(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 4, cost: 9_000);

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 4, reason: 'correction', user: $this->admin, unitCost: 11_500,
        );

        $batch = StockBatch::where('product_id', $this->product->id)->firstOrFail();

        $this->assertSame(11_500, (int) $batch->unit_cost, 'Rule 2: the batch cost is the price typed');
        $this->assertSame('correction', $adjustment->refresh()->reason);
    }

    public function test_an_outgoing_one_can_be_turned_into_an_incoming_one(): void
    {
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $adjustment = $this->adjust(StockAdjustment::DIRECTION_OUT, 3);
        $this->assertSame(7, $this->product->refresh()->quantity);

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 3, reason: 'miscount', user: $this->admin, unitCost: 10_000,
        );

        $this->assertSame(13, $this->product->refresh()->quantity,
            'The three that went out come back, and three more arrive');
    }

    /** The document number is the shop's reference for it, and an edit is not a new document. */
    public function test_the_document_number_survives_the_edit(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);
        $number = $adjustment->document_no;

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 2, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );

        $this->assertSame($number, $adjustment->refresh()->document_no);
    }

    /**
     * Section 8: every edit stores the previous version. The activity log used
     * to treat `quantity` as noise everywhere, because it is a cache on a
     * product — so the one change most worth a record of, correcting a count,
     * went unlogged.
     */
    public function test_correcting_the_count_is_recorded(): void
    {
        // activity_logs.user_id is a required FK, so the logger keeps quiet
        // when nobody is signed in. The shop always has somebody signed in.
        $this->actingAs($this->admin);

        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 3, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );

        $entry = ActivityLog::where('action', 'update')
            ->where('module', 'stock_adjustments')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'An edit to an adjustment must leave a trail');
        $this->assertSame(5, (int) $entry->old_values['quantity']);
    }

    /** The same guard the delete has: those units are on a customer's invoice. */
    public function test_it_refuses_once_the_units_it_added_have_been_sold(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $this->expectException(\RuntimeException::class);

        app(StockAdjustmentService::class)->update(
            adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
            quantity: 3, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );
    }

    public function test_nothing_moves_when_the_edit_is_refused(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        try {
            app(StockAdjustmentService::class)->update(
                adjustment: $adjustment, direction: StockAdjustment::DIRECTION_IN,
                quantity: 3, reason: 'miscount', user: $this->admin, unitCost: 9_000,
            );
        } catch (\RuntimeException) {
            // Expected — what matters is what is left behind.
        }

        $this->assertSame(5, (int) $adjustment->refresh()->quantity);
        $this->assertSame(3, $this->product->refresh()->quantity, 'Five in, two sold');
        $this->assertSame(1, StockBatch::where('product_id', $this->product->id)->count());
    }

    public function test_saving_a_correction_needs_the_edit_permission(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        $staff = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(
            Permission::whereIn('key', ['stock_adjustments.view'])->pluck('id')->all()
        );

        $correction = [
            'direction' => StockAdjustment::DIRECTION_IN,
            'quantity' => 3,
            'unit_cost' => 9_000,
            'reason' => 'miscount',
            'adjusted_at' => today()->toDateString(),
        ];

        $this->actingAs($staff)
            ->put(route('stock-adjustments.update', $adjustment), $correction)
            ->assertForbidden();

        // And the box is not offered on the screen either.
        $this->actingAs($staff)->get(route('stock-adjustments.show', $adjustment))
            ->assertOk()
            ->assertDontSee('#adjustment-edit', false);

        $staff->permissions()->syncWithoutDetaching(
            Permission::where('key', 'stock_adjustments.edit')->pluck('id')->all()
        );

        $this->actingAs($staff->refresh())
            ->put(route('stock-adjustments.update', $adjustment), $correction)
            ->assertSessionHasNoErrors();

        $this->assertSame(3, (int) $adjustment->refresh()->quantity);
    }

    /** Corrected where it was written, not on a screen of its own. */
    public function test_the_correction_box_is_on_the_list_and_on_the_adjustment(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        foreach ([route('stock-adjustments.index'), route('stock-adjustments.show', $adjustment)] as $screen) {
            $this->actingAs($this->admin)->get($screen)
                ->assertOk()
                ->assertSee('id="adjustment-edit"', false)
                ->assertSee('data-action="'.route('stock-adjustments.update', $adjustment).'"', false);
        }
    }

    public function test_the_screen_saves_the_correction(): void
    {
        $adjustment = $this->adjust(StockAdjustment::DIRECTION_IN, 5, cost: 9_000);

        $this->actingAs($this->admin)
            ->put(route('stock-adjustments.update', $adjustment), [
                'direction' => StockAdjustment::DIRECTION_IN,
                'quantity' => 3,
                'unit_cost' => 9_000,
                'reason' => 'miscount',
                'adjusted_at' => today()->toDateString(),
                'notes' => 'Recounted the shelf',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, (int) $adjustment->refresh()->quantity);
        $this->assertSame('Recounted the shelf', $adjustment->notes);
        $this->assertSame(3, $this->product->refresh()->quantity);
    }
}
