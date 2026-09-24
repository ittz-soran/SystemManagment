<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssemblyService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Undoing and redoing a take-apart — Soran, 2026-09-24: *"first add edit and
 * delete"*.
 *
 * ⚠️ **What came out has to still be there.** Undoing puts the pieces back into
 * the batches they were made from and takes them off the shelf, which cannot
 * happen once somebody has sold one.
 *
 * ⚠️ **An edit is a full undo and redo**, not a patch: the shelf goes back
 * exactly as it was and the new figures are applied from scratch, so an edit
 * can never leave half the old document behind.
 */
class AssemblyEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Product $bundle;

    private Supplier $seller;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->bundle = Product::create([
            'name' => 'Bundle Asus B450M + R5 5500', 'kind' => Product::KIND_STOCK,
            'sku' => 'SS26', 'barcode' => 'SS26-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 238_000, 'sale_price' => 300_000, 'quantity' => 0,
        ]);

        $this->seller = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $this->bundle->id, 'quantity' => 1, 'unit_price' => 238_000]],
            user: $this->user(), purchaseDate: now()->subDays(3), amountPaid: 238_000,
        );
    }

    private function user(): User
    {
        return User::first();
    }

    private function split(int $board = 119_000, int $cpu = 119_000): Assembly
    {
        return app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Board', 'quantity' => 1, 'unit_cost' => $board, 'sale_price' => 150_000],
                ['name' => 'CPU', 'quantity' => 1, 'unit_cost' => $cpu, 'sale_price' => 150_000],
            ],
            user: $this->user(),
            note: 'Test',
        );
    }

    private function piece(string $name): Product
    {
        return Product::where('name', $name)->firstOrFail();
    }

    /** What the units actually on the shelf cost, ignoring emptied batches. */
    private function shelfCost(string $name): int
    {
        return (int) $this->piece($name)->stockBatches()
            ->where('quantity_remaining', '>', 0)
            ->value('unit_cost');
    }

    // ---- Delete ------------------------------------------------------------

    /** ⚠️ Everything back as it was: the bundle whole, the pieces gone. */
    public function test_deleting_puts_the_bundle_back_together(): void
    {
        $assembly = $this->split();

        $this->assertSame(0, $this->bundle->fresh()->quantity);
        $this->assertSame(1, $this->piece('Board')->quantity);

        app(AssemblyService::class)->delete($assembly, $this->user());

        $this->assertSame(1, $this->bundle->fresh()->quantity, 'the bundle did not come back');
        $this->assertSame(0, $this->piece('Board')->quantity);
        $this->assertSame(0, $this->piece('CPU')->quantity);

        $this->assertSame(0, Assembly::count());
        $this->assertSame(1, Assembly::withTrashed()->count(), 'a delete is a reversal plus a hidden record');
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)->count());
    }

    /** ⚠️ A piece already sold is a piece there is nothing left to take back. */
    public function test_it_refuses_once_a_piece_has_been_sold(): void
    {
        $assembly = $this->split();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        $state = $assembly->fresh()->canBeDeleted($this->user());

        $this->assertFalse($state['allowed']);
        $this->assertStringContainsString('sold or used', $state['reason']);

        try {
            app(AssemblyService::class)->delete($assembly->fresh(), $this->user());
            $this->fail('it was undone with nothing to undo it with');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sold or used', $e->getMessage());
        }

        // ⚠️ And nothing was half-done.
        $this->assertSame(1, Assembly::count());
        $this->assertSame(0, $this->bundle->fresh()->quantity);
        $this->assertSame(1, $this->piece('Board')->quantity);
    }

    public function test_a_closed_period_refuses(): void
    {
        $assembly = $this->split();

        Setting::put('books_closed_before', today()->addDay()->toDateString());

        $this->assertFalse($assembly->fresh()->canBeDeleted($this->user())['allowed']);

        $this->expectException(RuntimeException::class);
        app(AssemblyService::class)->delete($assembly->fresh(), $this->user());
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        $assembly = $this->split();

        $maker = User::factory()->create(['role' => User::ROLE_USER]);
        $maker->permissions()->sync(
            Permission::whereIn('key', ['assemblies.view', 'assemblies.create'])->pluck('id')
        );

        $this->actingAs($maker)->delete(route('assemblies.destroy', $assembly))->assertForbidden();

        $this->assertSame(1, Assembly::count());
    }

    // ---- Edit --------------------------------------------------------------

    /** ⚠️ Soran's own correction: 119,000 / 119,000 becomes 150,000 / 88,000. */
    public function test_editing_rewrites_the_cost_split(): void
    {
        $assembly = $this->split();

        app(AssemblyService::class)->update(
            assembly: $assembly,
            sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
            results: [
                ['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 150_000],
                ['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 88_000],
            ],
            user: $this->user(),
            note: 'Corrected',
        );

        $assembly->refresh();

        $this->assertSame(238_000, $assembly->total_cost, 'it still has to balance');
        $this->assertSame('Corrected', $assembly->note);

        /*
         * What is on the shelf carries the new cost. ⚠️ Asked of the batch that
         * still HOLDS something: undoing empties the old batch rather than
         * removing it, the same as every other reversal in this shop, so a sum
         * over all of them counts an empty husk at its old price.
         */
        $this->assertSame(150_000, $this->shelfCost('Board'));
        $this->assertSame(88_000, $this->shelfCost('CPU'));

        // One of each on the shelf, not two — the old pieces were taken back.
        $this->assertSame(1, $this->piece('Board')->quantity);
        $this->assertSame(1, $this->piece('CPU')->quantity);
        $this->assertSame(0, $this->bundle->fresh()->quantity);

        // And the document is still the same document.
        $this->assertSame('ASM-00001', $assembly->document_no);
        $this->assertSame(2, $assembly->results()->count(), 'the old lines were left behind');
    }

    /** An edit that does not balance changes nothing at all. */
    public function test_an_edit_that_does_not_balance_leaves_the_old_one_standing(): void
    {
        $assembly = $this->split();

        try {
            app(AssemblyService::class)->update(
                assembly: $assembly,
                sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
                results: [['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 1]],
                user: $this->user(),
            );
            $this->fail('237,999 dinars went missing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('worth exactly what went in', $e->getMessage());
        }

        $assembly->refresh();

        $this->assertSame(238_000, $assembly->total_cost);
        $this->assertSame(1, $this->piece('Board')->quantity);
        $this->assertSame(1, $this->piece('CPU')->quantity, 'the CPU was dropped by a failed edit');
        $this->assertSame(0, $this->bundle->fresh()->quantity);
    }

    /** The pieces themselves can change, not only their costs. */
    public function test_editing_can_change_the_pieces(): void
    {
        $assembly = $this->split();

        app(AssemblyService::class)->update(
            assembly: $assembly,
            sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
            results: [
                ['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 100_000],
                ['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 100_000],
                ['name' => 'Cooler', 'quantity' => 1, 'unit_cost' => 38_000, 'sale_price' => 50_000],
            ],
            user: $this->user(),
        );

        $this->assertSame(3, $assembly->fresh()->results()->count());
        $this->assertSame(1, $this->piece('Cooler')->quantity);
        $this->assertSame(238_000, $assembly->fresh()->total_cost);
    }

    /** ⚠️ Both dates are checked: the day it was on and the day it moves to. */
    public function test_the_date_can_move_but_not_into_closed_books(): void
    {
        $assembly = $this->split();

        app(AssemblyService::class)->update(
            assembly: $assembly,
            sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
            results: [
                ['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 119_000],
                ['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 119_000],
            ],
            user: $this->user(),
            at: now()->subDays(2),
        );

        $this->assertTrue($assembly->fresh()->assembled_at->isSameDay(now()->subDays(2)));

        // The movements moved with it, or the audit trail would disagree.
        $this->assertTrue(
            StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)
                ->orderBy('id')->first()->occurred_at->isSameDay(now()->subDays(2)),
        );

        /*
         * ⚠️ Now the OTHER half: the day it is moving TO.
         *
         * The books close before a date, so a new date can only be inside them
         * by being earlier. The cutoff is set so this document's own date stays
         * open and only the destination is closed — otherwise the check on the
         * old date refuses it first and this proves nothing, which is exactly
         * what a first version of this test did.
         */
        Setting::put('books_closed_before', today()->subDays(5)->toDateString());

        $this->assertFalse(books_closed_on($assembly->fresh()->assembled_at), 'the fixture closes the wrong date');

        try {
            app(AssemblyService::class)->update(
                assembly: $assembly->fresh(),
                sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
                results: [
                    ['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 119_000],
                    ['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 119_000],
                ],
                user: $this->user(),
                at: now()->subDays(10),
            );
            $this->fail('a document was moved into closed books');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('closed period', $e->getMessage());
        }

        $this->assertTrue($assembly->fresh()->assembled_at->isSameDay(now()->subDays(2)), 'it moved anyway');
    }

    /**
     * An edit cannot happen once a piece is sold, for the same reason — and it
     * says so in the document's own words.
     *
     * ⚠️ The MESSAGE is asserted, not merely that something was thrown. FIFO
     * refuses this anyway, several layers down, with "has since been sold or
     * written off" — so a test that only expected a RuntimeException passed
     * with the friendly up-front check deleted, and the shopkeeper would have
     * got the engine's words instead of the screen's.
     */
    public function test_editing_refuses_once_a_piece_has_been_sold(): void
    {
        $assembly = $this->split();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        try {
            app(AssemblyService::class)->update(
                assembly: $assembly->fresh(),
                sources: [['product_id' => $this->bundle->id, 'quantity' => 1]],
                results: [['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 238_000]],
                user: $this->user(),
            );
            $this->fail('a document was rewritten with a piece already sold');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sold or used', $e->getMessage());
        }

        $this->assertSame(238_000, $assembly->fresh()->total_cost, 'the old document was disturbed');
    }

    // ---- The screens -------------------------------------------------------

    public function test_the_document_offers_edit_and_delete(): void
    {
        $assembly = $this->split();

        $this->actingAs($this->user())
            ->get(route('assemblies.show', $assembly))
            ->assertOk()
            ->assertSee(__('Edit'))
            ->assertSee(__('Delete'));
    }

    /** ⚠️ The edit form opens on what the document says, not on an empty row. */
    public function test_the_edit_form_opens_on_the_documents_own_lines(): void
    {
        $assembly = $this->split();

        $page = $this->actingAs($this->user())
            ->get(route('assemblies.edit', $assembly))
            ->assertOk();

        $page->assertSee(__('Save the changes'))
            ->assertSee($assembly->assembled_at->toDateString())
            // The source is still offered even though the shelf says zero,
            // because an edit puts it back first.
            ->assertSee('Bundle Asus B450M + R5 5500');

        $existing = $page->viewData('assembly')->pieces();

        $this->assertSame(2, $existing->count());
        $this->assertSame(119_000, $existing->first()->unit_cost);
    }

    /**
     * ⚠️ **The edit form can read what its own source cost.**
     *
     * The bundle's batch is empty — this very document emptied it — so the form
     * opened with its source reading no cost at all: the remainder never
     * reached zero and the save button never lit. An edit unwinds before it
     * re-applies, so those units are put back into the list first.
     *
     * Found by editing one in a browser. Every test passed without it, because
     * the figures the form works from live in the page's data rather than in
     * anything the server asserts on its own.
     */
    public function test_the_edit_form_can_still_price_the_thing_it_took_apart(): void
    {
        $assembly = $this->split();

        $this->assertSame(0, $this->bundle->fresh()->quantity, 'the fixture does not reproduce the problem');

        $available = $this->actingAs($this->user())
            ->get(route('assemblies.edit', $assembly))
            ->assertOk()
            ->viewData('available');

        $source = collect($available)->firstWhere('id', $this->bundle->id);

        $this->assertNotNull($source, 'the thing being taken apart is not on the form at all');
        $this->assertSame(1, $source['quantity'], 'it reads as nothing on the shelf, so the form cannot price it');

        $worth = collect($source['batches'])->sum(fn (array $b) => $b['cost'] * $b['left']);

        $this->assertSame(238_000, $worth, 'the form would show a remainder that never reaches zero');
    }

    public function test_the_form_saves_an_edit(): void
    {
        $assembly = $this->split();

        $this->actingAs($this->user())->put(route('assemblies.update', $assembly), [
            'assembled_at' => now()->subDay()->toDateString(),
            'note' => 'Corrected on the screen',
            'whole' => ['product_id' => $this->bundle->id, 'quantity' => 1],
            'pieces' => [
                ['product_id' => $this->piece('Board')->id, 'quantity' => 1, 'unit_cost' => 140_000],
                ['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_cost' => 98_000],
            ],
        ])->assertRedirect(route('assemblies.show', $assembly))->assertSessionHas('success');

        $this->assertSame('Corrected on the screen', $assembly->fresh()->note);
        $this->assertSame(140_000, $this->shelfCost('Board'));
    }

    public function test_the_form_deletes(): void
    {
        $assembly = $this->split();

        $this->actingAs($this->user())
            ->delete(route('assemblies.destroy', $assembly))
            ->assertRedirect(route('assemblies.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, $this->bundle->fresh()->quantity);
    }

    /** A button that cannot work says why rather than failing when pressed. */
    public function test_the_buttons_are_disabled_with_their_reason(): void
    {
        $assembly = $this->split();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->piece('CPU')->id, 'quantity' => 1, 'unit_price' => 150_000]],
            user: $this->user(), saleDate: now(), amountPaid: 150_000, paymentMethod: 'cash',
        );

        $page = $this->actingAs($this->user())
            ->get(route('assemblies.show', $assembly))
            ->assertOk();

        $page->assertSee('sold or used', false);

        $this->assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*>\s*<i class="bi bi-trash/',
            $page->getContent(),
            'the delete button is still live on a document that cannot be undone',
        );
    }
}
