<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SwapService;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Correcting how many were handed over — Soran, 2026-09-25.
 *
 * ⚠️ **An edit here moves stock twice and touches a supplier's account**, so
 * these tests care much less about the form than about what the shelf, the
 * books and the invoice line say afterwards. The rule they hold is one
 * sentence: **a swap corrected to N must be indistinguishable from a swap
 * made at N in the first place.**
 */
class SwapQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Customer $karwan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD17UK', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function buy(int $quantity, int $cost = 40_000, int $daysAgo = 60): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(int $quantity = 1): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: today(), amountPaid: $quantity * 60_000, paymentMethod: 'cash',
        );
    }

    /** How the shop looks, in the three numbers a correction must get right. */
    private function shape(): array
    {
        return [
            'shelf' => (int) $this->pd->fresh()->quantity,
            'swapped' => (int) SaleItem::firstOrFail()->quantity_swapped,
            'supplier_credit' => (int) PurchaseReturn::sum('total_amount'),
        ];
    }

    // ---- The rule ----------------------------------------------------------

    /**
     * ⚠️ **The whole test file in one assertion.** A swap made at one and
     * corrected to two must leave the shop in exactly the state a swap made at
     * two would have — the shelf, the invoice line, the supplier's credit, the
     * movements and the document's own costs.
     */
    public function test_a_swap_corrected_to_two_is_the_same_as_a_swap_made_at_two(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);

        $corrected = app(SwapService::class)->create($sale->items->first(), 1, $this->user());
        app(SwapService::class)->update($corrected, 2, $this->user());

        $afterCorrecting = $this->shape();
        $costAfterCorrecting = $corrected->fresh()->cost();
        $movementsAfterCorrecting = StockMovement::where('reference_type', StockMovement::REF_SWAP)
            ->orderBy('id')->get()->map(fn ($m) => [$m->quantity, $m->unit_cost])->all();

        // Now the same shop again, swapped at two from the start.
        $this->refreshDatabaseForComparison();

        $this->buy(10);
        $sale = $this->sell(3);
        $straight = app(SwapService::class)->create($sale->items->first(), 2, $this->user());

        $this->assertSame($this->shape(), $afterCorrecting, 'the shop does not look the same');
        $this->assertSame($straight->cost(), $costAfterCorrecting, 'the document does not cost the same');
        $this->assertSame(
            StockMovement::where('reference_type', StockMovement::REF_SWAP)
                ->orderBy('id')->get()->map(fn ($m) => [$m->quantity, $m->unit_cost])->all(),
            $movementsAfterCorrecting,
            'the movements are not the same',
        );
    }

    /** Wipe the trade but keep the shop, so the two halves start level. */
    private function refreshDatabaseForComparison(): void
    {
        // ⚠️ A return movement points at the sale movement it undoes, and that
        // foreign key restricts deletes. The pointers have to go before the
        // rows do — and `PRAGMA foreign_keys` cannot be turned off from inside
        // the transaction RefreshDatabase holds us in.
        DB::table('stock_movements')->update(['reverses_movement_id' => null]);
        DB::table('stock_movements')->delete();
        DB::table('stock_batches')->delete();
        DB::table('swaps')->delete();
        DB::table('purchase_return_items')->delete();
        DB::table('purchase_returns')->delete();
        DB::table('sale_items')->delete();
        DB::table('sales')->delete();
        DB::table('purchase_items')->delete();
        DB::table('purchases')->delete();
        DB::table('payments')->delete();
        DB::table('account_transactions')->delete();

        $this->pd->forceFill(['quantity' => 0])->save();
        $this->bazaar->forceFill(['balance' => 0])->save();
        $this->karwan->forceFill(['balance' => 0])->save();
    }

    /**
     * ⚠️ **The profit report, not just the shelf** — Soran, 2026-09-26: *"i
     * fell profit is wrong"*, the day after corrections shipped.
     *
     * `TradeProfit` reads the swap's cost off its movements **filtered by
     * `occurred_at`**. A correction deletes those movements and writes them
     * again, so the one thing that could quietly wreck a month is the new pair
     * landing on the day of the correction instead of the day of the swap.
     * Everything nets out inside one month and nothing shows; across a month
     * boundary the cost moves to the wrong month, and the two months are wrong
     * in opposite directions while the year still adds up.
     *
     * So the swap here is LAST month and the correction is today.
     */
    public function test_correcting_an_old_swap_leaves_the_cost_in_its_own_month(): void
    {
        $when = today()->subMonthNoOverflow()->startOfMonth()->addDays(9);

        $this->buy(4, 40_000, 120);
        $this->buy(10, 44_000, 90);

        $sale = app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => 3, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: $when, amountPaid: 180_000, paymentMethod: 'cash',
        );

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user(), $when);
        $this->assertSame($when->toDateString(), $swap->swapped_at->toDateString());

        app(SwapService::class)->update($swap, 2, $this->user());

        $lastMonth = [$when->copy()->startOfMonth(), $when->copy()->endOfMonth()];
        $thisMonth = [today()->startOfMonth(), today()->endOfDay()];

        // Every swap movement is still dated the day of the swap.
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_SWAP)
            ->whereBetween('occurred_at', $thisMonth)->count(),
            'correcting an old swap wrote its cost into the month it was corrected in');

        $stock = Product::ofKind(Product::KIND_STOCK);

        $this->assertSame(
            $swap->fresh()->cost(),
            $this->swapCostIn($stock, $lastMonth),
            'the report does not carry the corrected figure',
        );

        // And nothing at all leaked into this month.
        $this->assertSame(0, TradeProfit::between($stock, ...$thisMonth)['cost']);
    }

    /**
     * What the profit report counts as the swap term, over one window.
     *
     * ⚠️ Rebuilt from the movements the report reads, not from the document,
     * so it can disagree with `Swap::cost()` — which is the whole point.
     */
    private function swapCostIn($products, array $window): int
    {
        return -(int) StockMovement::query()
            ->whereIn('product_id', $products->clone()->select('id'))
            ->where('reference_type', StockMovement::REF_SWAP)
            ->whereBetween('occurred_at', $window)
            ->sum(DB::raw(StockMovement::VALUE));
    }

    /**
     * ⚠️ **A swap corrected to N earns the same profit as a swap made at N**,
     * asked of the report rather than of the swap. The document agreeing with
     * itself proves nothing — that was the mistake the first swap guard in
     * `AccountingAgreesTest` made, and a sabotage walked through it.
     */
    public function test_the_profit_after_a_correction_is_the_profit_of_the_figure_it_was_corrected_to(): void
    {
        $this->buy(4, 40_000, 120);
        $this->buy(20, 44_000, 90);
        $sale = $this->sell(3);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());
        app(SwapService::class)->update($swap, 3, $this->user());

        $window = [today()->startOfMonth(), today()->endOfDay()];
        $corrected = TradeProfit::between(Product::ofKind(Product::KIND_STOCK), ...$window);

        // The same shop again, swapped at three from the start.
        $this->refreshDatabaseForComparison();

        $this->buy(4, 40_000, 120);
        $this->buy(20, 44_000, 90);
        $sale = $this->sell(3);
        app(SwapService::class)->create($sale->items->first(), 3, $this->user());

        $straight = TradeProfit::between(Product::ofKind(Product::KIND_STOCK), ...$window);

        $this->assertSame($straight, $corrected, 'the P&L can tell a corrected swap from a straight one');
        $this->assertGreaterThan(0, $straight['cost'], 'the fixture must actually cost something');
    }

    // ---- Down as well as up -------------------------------------------------

    public function test_a_swap_can_be_brought_back_down(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);

        $swap = app(SwapService::class)->create($sale->items->first(), 3, $this->user());
        $this->assertSame(3, (int) SaleItem::firstOrFail()->quantity_swapped);

        app(SwapService::class)->update($swap, 1, $this->user());

        $this->assertSame(1, (int) $swap->fresh()->quantity);
        $this->assertSame(1, (int) SaleItem::firstOrFail()->quantity_swapped);
        $this->assertSame(2, SaleItem::firstOrFail()->returnableQuantity(), 'the other two can come back again');

        // 10 bought, 3 sold, and one swapped: one faulty in, one out to the
        // supplier, one replacement out.
        $this->assertSame(6, (int) $this->pd->fresh()->quantity);
        $this->assertSame(40_000, (int) PurchaseReturn::sum('total_amount'), 'the supplier is billed for one, not three');
    }

    /**
     * ⚠️ **The costs are cleared before they are written again.** `apply()`
     * only ADDS, so a swap brought from three down to one would otherwise keep
     * the cost of a handover that no longer happened — and `cost()` is what the
     * profit report reads.
     */
    public function test_coming_down_does_not_leave_the_old_cost_behind(): void
    {
        $this->buy(2, 40_000, 60);
        $this->buy(10, 44_000, 30);
        $sale = $this->sell(2);

        $swap = app(SwapService::class)->create($sale->items->first(), 2, $this->user());
        $this->assertSame(8_000, $swap->cost(), 'two replacements off the dearer layer');

        app(SwapService::class)->update($swap, 1, $this->user());

        $this->assertSame(4_000, $swap->fresh()->cost(), 'one, not still two');
    }

    /**
     * ⚠️ **A correction is asked the same question a new swap is.** The line
     * sold three: two off a batch the shop already had, with no supplier
     * behind them, and one it bought. Units come back in the reverse of the
     * order they went out, so the first faulty one is the purchased one and
     * the second reaches a unit nobody sold the shop. A swap cannot be half
     * one and half the other — the mixed line is refused at `create()` and
     * must be refused here too, with the whole thing put back as it was,
     * supplier bill and all.
     */
    public function test_a_correction_cannot_make_a_mixed_swap(): void
    {
        // Oldest layer first: two the shop already had, then ten it bought.
        app(StockAdjustmentService::class)->create(
            product: $this->pd, direction: 'in', quantity: 2, reason: 'correction',
            user: $this->user(), unitCost: 40_000, notes: 'opening stock',
            adjustedAt: now()->subDays(90),
        );
        $this->buy(10, 44_000, 60);

        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertNotNull($swap->purchase_return_id, 'the one that was bought goes back to the supplier');
        $this->assertSame(44_000, (int) $swap->faulty_cost);

        $before = $this->shape();

        try {
            app(SwapService::class)->update($swap, 2, $this->user());
            $this->fail('a mixed swap was allowed through a correction');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('came from a purchase and some did not', $e->getMessage());
        }

        $this->assertSame($before, $this->shape(), 'the shop moved on a correction that was refused');
        $this->assertSame(1, (int) $swap->fresh()->quantity);
        $this->assertSame(44_000, (int) $swap->fresh()->faulty_cost);
        $this->assertSame(1, PurchaseReturn::count(), 'the supplier was un-billed and left that way');
    }

    /** The document keeps its number and its place in the history. */
    public function test_the_document_number_survives_the_correction(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());
        $number = $swap->document_no;
        $id = $swap->id;

        app(SwapService::class)->update($swap, 2, $this->user());

        $this->assertSame($number, $swap->fresh()->document_no);
        $this->assertSame($id, $swap->fresh()->id);
        $this->assertSame(1, Swap::count(), 'one document, corrected — not a second one');
    }

    // ---- What it refuses ----------------------------------------------------

    /**
     * ⚠️ **The shelf is read AFTER the undo.** A shop holding exactly one spare
     * must still be able to correct the swap it just made — its own replacement
     * is coming back before the new one goes out.
     */
    public function test_the_last_spare_can_still_be_used_to_correct_its_own_swap(): void
    {
        $this->buy(4);
        $sale = $this->sell(3);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        // 4 bought, 3 sold, 1 faulty in, 1 out to the supplier, 1 replacement
        // out: nothing on the shelf at all.
        $this->assertSame(0, (int) $this->pd->fresh()->quantity);

        app(SwapService::class)->update($swap, 1, $this->user());

        $this->assertSame(1, (int) $swap->fresh()->quantity, 'a correction to the same figure still goes through');
        $this->assertSame(0, (int) $this->pd->fresh()->quantity);
    }

    public function test_it_refuses_more_than_the_line_ever_sold(): void
    {
        $this->buy(10);
        $sale = $this->sell(2);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only 2 of that line can still come back');

        app(SwapService::class)->update($swap, 3, $this->user());
    }

    /** And nothing is half-done when it refuses. */
    public function test_a_refused_correction_leaves_the_swap_exactly_as_it_was(): void
    {
        $this->buy(10);
        $sale = $this->sell(2);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());
        $before = $this->shape();
        $cost = $swap->cost();

        try {
            app(SwapService::class)->update($swap, 9, $this->user());
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($before, $this->shape(), 'the shop moved on a correction that was refused');
        $this->assertSame(1, (int) $swap->fresh()->quantity);
        $this->assertSame($cost, $swap->fresh()->cost());
        $this->assertSame(1, PurchaseReturn::count(), 'the supplier was re-billed and left that way');
    }

    /**
     * ⚠️ A swap whose faulty units have since been sold cannot be undone, so it
     * cannot be corrected either — the same refusal, for the same reason.
     */
    public function test_a_swap_that_can_no_longer_be_undone_cannot_be_corrected(): void
    {
        $this->buy(10);
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        // Somebody deletes the supplier return by hand, and the faulty unit
        // sitting back in its batch is then sold to another customer.
        app(PurchaseReturnService::class)
            ->delete($swap->purchaseReturn, $this->user(), alreadyAuthorised: true);

        // 10 bought, 1 sold, and the faulty one back in its batch: 9 on the
        // shelf, and every one of them goes.
        $this->sell(9);

        $this->assertFalse($swap->fresh()->canBeChanged()['allowed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('can no longer be undone');

        app(SwapService::class)->update($swap->fresh(), 1, $this->user());
    }

    // ---- Who may do it ------------------------------------------------------

    /**
     * ⚠️ **No existing permission was quietly widened.** `swaps.edit` was sold
     * to shops as "correct the note on a swap"; correcting a quantity un-bills
     * a supplier and moves stock twice, so it asks for `swaps.delete` too.
     */
    public function test_correcting_a_quantity_needs_the_key_that_undoes_one(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $noteOnly = User::factory()->create(['role' => User::ROLE_USER]);
        $noteOnly->permissions()->sync(
            Permission::whereIn('key', ['swaps.view', 'swaps.edit'])->pluck('id')
        );

        $state = $swap->canBeChanged($noteOnly->load('permissions'));

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('same permission as deleting', $state['reason']);

        // The page offers the note and not the quantity, and says why.
        $this->actingAs($noteOnly)->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertSee(__('Save note'))
            ->assertDontSee(__('Save changes'))
            ->assertSee('same permission as deleting', false);

        // And posting one anyway changes nothing.
        $this->actingAs($noteOnly)->patch(route('swaps.update', $swap), [
            'quantity' => 2, 'note' => 'nice try',
        ])->assertSessionHas('error');

        $this->assertSame(1, (int) $swap->fresh()->quantity);
    }

    /** With both keys, the field is there and it works through the page. */
    public function test_somebody_with_both_keys_can_correct_it_from_the_page(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->actingAs($this->user())->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertSee(__('Save changes'))
            ->assertSee(__('How many'));

        $this->actingAs($this->user())->patch(route('swaps.update', $swap), [
            'quantity' => 2, 'note' => 'both dead',
        ])->assertRedirect(route('swaps.show', $swap))->assertSessionHas('success');

        $this->assertSame(2, (int) $swap->fresh()->quantity);
        $this->assertSame('both dead', $swap->fresh()->note);
        $this->assertSame(2, (int) SaleItem::firstOrFail()->quantity_swapped);
    }

    /** The note on its own still takes the road it always took. */
    public function test_saving_only_the_note_moves_no_stock(): void
    {
        $this->buy(10);
        $sale = $this->sell(3);
        $swap = app(SwapService::class)->create($sale->items->first(), 2, $this->user());

        $before = $this->shape();
        $movements = StockMovement::count();

        $this->actingAs($this->user())->patch(route('swaps.update', $swap), [
            'quantity' => 2, 'note' => 'just a note',
        ])->assertSessionHas('success');

        $this->assertSame('just a note', $swap->fresh()->note);
        $this->assertSame($before, $this->shape());
        $this->assertSame($movements, StockMovement::count(), 'a note wrote a stock movement');
    }
}
