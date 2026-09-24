<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\SwapService;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Undoing a swap — Soran, 2026-09-24.
 *
 * ⚠️ **Backwards through exactly what making one did, and the order is the
 * whole of it.** The supplier is un-billed first, because that is what puts the
 * faulty unit back into its batch — and the movement removed second is the one
 * that put it there, which cannot come off a batch that has not got it.
 *
 * ⚠️ **A swap deleted is a swap that never happened, on the books.** The
 * customer keeps whatever they walked out with. What comes back is the shelf,
 * the supplier's balance, and the invoice line's right to be returned.
 */
class SwapDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD-17-UK-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
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
        return Sale::find(app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now()->subDays(5),
            amountPaid: $quantity * 60_000, paymentMethod: 'cash',
        )->id);
    }

    private function madeSwap(int $quantity = 1): Swap
    {
        return app(SwapService::class)->create(
            Sale::first()->items->first(), $quantity, $this->user(),
        );
    }

    // ---- The reversal -----------------------------------------------------

    /** ⚠️ Everything the swap touched, back where it was. */
    public function test_deleting_a_swap_puts_the_shelf_the_supplier_and_the_line_back(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $shelfBefore = $this->pd->fresh()->quantity;
        $owedBefore = $this->bazaar->fresh()->balance;

        $swap = $this->madeSwap();

        $this->assertSame(1, $this->pd->fresh()->quantity, 'the replacement left the shelf');
        $this->assertSame(1, PurchaseReturn::count(), 'the supplier was billed');
        $this->assertSame(1, $sale->items->first()->fresh()->quantity_swapped);

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame($shelfBefore, $this->pd->fresh()->quantity, 'the replacement did not come back');
        $this->assertSame($owedBefore, $this->bazaar->fresh()->balance, 'the supplier is still billed');
        $this->assertSame(0, PurchaseReturn::count(), 'the purchase return is still there');
        $this->assertSame(0, $sale->items->first()->fresh()->quantity_swapped);
        $this->assertSame(0, Swap::count());
        $this->assertSame(1, Swap::withTrashed()->count(), 'a delete is a reversal plus a hidden record');
    }

    /**
     * ⚠️ **The order is the whole of it, and this is the test that says so.**
     *
     * The supplier must be un-billed FIRST, because that is what puts the
     * faulty unit back into its batch — and the movement removed second is the
     * one that put it there, which cannot come off a batch that has not got it.
     *
     * It only bites when that batch has been emptied, which is why the ordinary
     * case above cannot see it: with other units still in the batch, the check
     * passes either way. Here the faulty unit is the last of its batch and the
     * replacement comes off a different one, so reversing the two steps refuses
     * a swap that is perfectly undoable.
     */
    public function test_the_supplier_is_un_billed_before_the_movements_come_off(): void
    {
        $bought = $this->buy(1, 40_000, daysAgo: 60);

        // A second batch, so there is something to hand over as the replacement.
        $other = Supplier::create(['name' => 'Sulaimani Traders', 'phone' => '0771', 'is_active' => true]);
        app(PurchaseService::class)->create(
            supplier: $other,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 44_000]],
            user: $this->user(), purchaseDate: now()->subDays(30), amountPaid: 88_000,
        );

        $this->sell(1);
        $swap = $this->madeSwap();

        // The faulty unit's own batch is empty: it went in and straight out to
        // Bazaar, and it was the only thing that batch ever held.
        $batch = $bought->batches()->firstOrFail();
        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame(0, Swap::count());
        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(0, $batch->fresh()->quantity_remaining, 'the faulty unit is back out of its batch');
        // Three bought, one still sold: the sale itself is untouched.
        $this->assertSame(2, $this->pd->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_SWAP)->count());
    }

    /** No trace left in the audit table, because nothing happened. */
    public function test_both_swap_movements_come_off(): void
    {
        $this->buy(3);
        $this->sell(1);

        $swap = $this->madeSwap();

        $this->assertSame(2, StockMovement::where('reference_type', StockMovement::REF_SWAP)
            ->where('reference_id', $swap->id)->count(), 'one in, one out');

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_SWAP)->count());
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_PURCHASE_RETURN)->count());
    }

    /** ⚠️ The line is returnable again, and really is — not just on the counter. */
    public function test_the_line_can_be_returned_after_the_swap_is_undone(): void
    {
        $this->buy(3);
        $sale = $this->sell(1);

        $swap = $this->madeSwap();

        $this->assertSame(0, $sale->items->first()->fresh()->returnableQuantity());

        app(SwapService::class)->delete($swap, $this->user());

        $line = $sale->items->first()->fresh();
        $this->assertSame(1, $line->returnableQuantity());

        $return = app(SaleReturnService::class)->create(
            sale: Sale::first(),
            lines: [['sale_item_id' => $line->id, 'quantity' => 1]],
            user: $this->user(),
            returnDate: now(),
        );

        $this->assertSame(1, $return->items->count());
        $this->assertSame(3, $this->pd->fresh()->quantity, 'the returned unit went back on the shelf');
    }

    /** ⚠️ And the profit figure lets go of the swap's cost. */
    public function test_the_profit_figure_goes_back_to_what_it_was(): void
    {
        $this->buy(1, 40_000, daysAgo: 60);
        $this->buy(1, 44_000, daysAgo: 30);
        $this->sell(1);

        $window = [now()->subYear(), now()->addDay()];
        $before = TradeProfit::between(Product::whereKey($this->pd->id), ...$window);

        $swap = $this->madeSwap();

        $this->assertSame(16_000, TradeProfit::between(Product::whereKey($this->pd->id), ...$window)['profit']);

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame($before, TradeProfit::between(Product::whereKey($this->pd->id), ...$window));
    }

    /** Nothing came from a purchase, so nobody was billed — and it still undoes. */
    public function test_a_swap_with_no_supplier_behind_it_still_undoes(): void
    {
        // Opening stock: an incoming adjustment, which has no purchase behind it.
        app(StockAdjustmentService::class)->recordOpeningStock(
            product: $this->pd, quantity: 3, unitCost: 40_000, user: $this->user(),
        );

        $this->sell(1);

        $swap = $this->madeSwap();

        $this->assertNull($swap->purchase_return_id, 'nobody to bill');

        // The faulty unit never came back — there was nobody to send it to —
        // so the shelf is down by the replacement alone.
        $this->assertSame(1, $this->pd->fresh()->quantity);
        $this->assertSame(40_000, $swap->cost());

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame(2, $this->pd->fresh()->quantity, 'the replacement did not come back');
        $this->assertSame(0, Swap::count());
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_SWAP)->count());
    }

    /** Two at once come off together. */
    public function test_a_swap_of_two_undoes_both(): void
    {
        $this->buy(5);
        $sale = $this->sell(2);

        $swap = $this->madeSwap(2);

        $this->assertSame(1, $this->pd->fresh()->quantity);

        app(SwapService::class)->delete($swap, $this->user());

        $this->assertSame(3, $this->pd->fresh()->quantity);
        $this->assertSame(0, $sale->items->first()->fresh()->quantity_swapped);
    }

    // ---- When it must refuse ----------------------------------------------

    public function test_a_closed_period_refuses(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        Setting::put('books_closed_before', today()->addDay()->toDateString());

        $state = $swap->fresh()->canBeDeleted($this->user());

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('closed period', $state['reason']);

        $this->expectException(RuntimeException::class);
        app(SwapService::class)->delete($swap->fresh(), $this->user());
    }

    public function test_it_needs_the_permission(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        $reader = User::factory()->create(['role' => User::ROLE_USER]);
        $reader->permissions()->sync(Permission::whereIn('key', ['swaps.view'])->pluck('id'));

        $state = $swap->canBeDeleted($reader);

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('permission', $state['reason']);

        $this->actingAs($reader)
            ->delete(route('swaps.destroy', $swap))
            ->assertForbidden();

        $this->assertSame(1, Swap::count());
    }

    /**
     * ⚠️ The purchase return was deleted by hand, and the faulty unit has been
     * sitting in its batch ever since — and somebody has sold it.
     */
    public function test_it_refuses_when_the_faulty_unit_has_since_been_sold(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        // Undo the supplier side on its own, which puts the faulty unit back.
        app(PurchaseReturnService::class)->delete(PurchaseReturn::firstOrFail(), $this->user());

        $this->assertSame(2, $this->pd->fresh()->quantity);

        // Then sell everything, so there is nothing left to take back.
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now(), amountPaid: 120_000, paymentMethod: 'cash',
        );

        $state = $swap->fresh()->canBeDeleted($this->user());

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('sold or written off', $state['reason']);

        try {
            app(SwapService::class)->delete($swap->fresh(), $this->user());
            $this->fail('the swap was undone with nothing to undo it with');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sold or written off', $e->getMessage());
        }

        // ⚠️ And nothing was half-done.
        $this->assertSame(1, Swap::count());
        $this->assertSame(0, $this->pd->fresh()->quantity);
        $this->assertSame(1, Sale::first()->items->first()->fresh()->quantity_swapped);
    }

    /** But if it is still sitting there, the swap undoes as usual. */
    public function test_it_allows_when_the_supplier_side_was_undone_and_the_unit_is_still_there(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        app(PurchaseReturnService::class)->delete(PurchaseReturn::firstOrFail(), $this->user());

        $this->assertTrue($swap->fresh()->canBeDeleted($this->user())['allowed']);

        app(SwapService::class)->delete($swap->fresh(), $this->user());

        // The faulty unit came out of the batch again and the replacement
        // returned: three bought, one sold, and neither of the swap's two
        // movements left behind.
        $this->assertSame(2, $this->pd->fresh()->quantity);
        $this->assertSame(0, Swap::count());
    }

    // ---- The screen -------------------------------------------------------

    public function test_the_document_offers_the_button_and_the_delete_works_from_it(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        $this->actingAs($this->user())
            ->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertSee(__('Delete swap'));

        $this->actingAs($this->user())
            ->delete(route('swaps.destroy', $swap))
            ->assertRedirect(route('swaps.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, Swap::count());
        $this->assertSame(2, $this->pd->fresh()->quantity);
    }

    /** A button that cannot work says why, rather than failing when pressed. */
    public function test_the_button_is_disabled_with_its_reason_on_the_page(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        app(PurchaseReturnService::class)->delete(PurchaseReturn::firstOrFail(), $this->user());
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now(), amountPaid: 120_000, paymentMethod: 'cash',
        );

        $page = $this->actingAs($this->user())->get(route('swaps.show', $swap))->assertOk();

        $page->assertSee('sold or written off', false);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*>\s*<i class="bi bi-trash[^>]*><\/i>/',
            $page->getContent(),
            'the delete button is still live on a swap that cannot be undone',
        );
    }

    // ---- The note ---------------------------------------------------------

    public function test_the_note_can_be_corrected(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        $this->actingAs($this->user())
            ->patch(route('swaps.update', $swap), ['note' => 'Not charging at all'])
            ->assertRedirect(route('swaps.show', $swap))
            ->assertSessionHas('success');

        $this->assertSame('Not charging at all', $swap->fresh()->note);
    }

    /** ⚠️ And nothing else on the document moves with it. */
    public function test_correcting_the_note_changes_nothing_else(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        $before = $swap->only(['quantity', 'replacement_cost', 'faulty_cost', 'purchase_return_id', 'sale_item_id']);
        $shelf = $this->pd->fresh()->quantity;

        $this->actingAs($this->user())->patch(route('swaps.update', $swap), [
            'note' => 'Corrected',
            // Sent anyway, the way a hand-edited form would.
            'quantity' => 99,
            'sale_item_id' => 12_345,
        ])->assertRedirect();

        $this->assertSame($before, $swap->fresh()->only(['quantity', 'replacement_cost', 'faulty_cost', 'purchase_return_id', 'sale_item_id']));
        $this->assertSame($shelf, $this->pd->fresh()->quantity);
        $this->assertSame(2, StockMovement::where('reference_type', StockMovement::REF_SWAP)->count());
    }

    public function test_the_note_needs_its_own_permission(): void
    {
        $this->buy(3);
        $this->sell(1);
        $swap = $this->madeSwap();

        $reader = User::factory()->create(['role' => User::ROLE_USER]);
        $reader->permissions()->sync(Permission::whereIn('key', ['swaps.view'])->pluck('id'));

        $this->actingAs($reader)
            ->patch(route('swaps.update', $swap), ['note' => 'sneaky'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->get(route('swaps.show', $swap))
            ->assertOk()
            ->assertDontSee(__('Save note'));

        $this->assertNotSame('sneaky', $swap->fresh()->note);
    }
}
