<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldCart;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cart put down, and picked up later.
 *
 * The rule this whole feature stands on is what a held cart does NOT do. It
 * takes no document number, opens no batch, moves no stock and posts nothing to
 * the ledger. Nothing in the shop's books knows it exists.
 *
 * That is not tidiness. A cart that "reserved" its twenty-five units would hide
 * them from the till at the other end of the counter — the shelf would say one
 * thing and the room another, and FIFO would be picking from the wrong one.
 */
class HeldCartTest extends TestCase
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
            'name' => 'USB-C cable',
            'sku' => 'SS-1',
            'category_id' => Category::firstOrCreate(['name' => 'Cables'])->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 3_000,
            'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Erbil Electronics']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
            user: $this->admin,
            purchaseDate: now(),
        );
    }

    private function hold(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson(route('held-carts.store'), [
            'type' => 'sale',
            'note' => 'Karwan, gone for his wallet',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 4,
                'unit_price' => 3_000,
            ]],
            ...$overrides,
        ]);
    }

    /** The whole point: it is a note to self, not a document. */
    public function test_holding_a_cart_writes_nothing_to_the_books(): void
    {
        $movementsBefore = StockMovement::count();
        $batchesBefore = StockBatch::sum('quantity_remaining');
        // The purchase in setUp put a row here; holding must add none.
        $ledgerBefore = \App\Models\AccountTransaction::count();

        $this->hold()->assertOk();

        $this->assertSame(1, HeldCart::count());

        // No document, no number consumed, no stock moved, nothing on the shelf
        // touched, and nothing in the ledger.
        $this->assertSame(0, Sale::count());
        $this->assertSame($movementsBefore, StockMovement::count());
        $this->assertSame($batchesBefore, StockBatch::sum('quantity_remaining'));
        $this->assertSame(10, $this->product->fresh()->quantity);
        $this->assertSame($ledgerBefore, \App\Models\AccountTransaction::count());
    }

    /** Twenty-five things scanned and no customer yet is the case it exists for. */
    public function test_a_cart_can_be_held_with_nobody_chosen(): void
    {
        $this->hold(['party_id' => null])->assertOk();

        $this->assertNull(HeldCart::sole()->payload['party_id']);
    }

    public function test_the_cart_waits_on_the_screen_it_belongs_to(): void
    {
        $this->hold()->assertOk();

        $this->actingAs($this->admin)->get(route('sales.create'))
            ->assertOk()
            ->assertSee('Karwan, gone for his wallet');

        // A sale cart is not a purchase cart.
        $this->actingAs($this->admin)->get(route('purchases.create'))
            ->assertOk()
            ->assertDontSee('Karwan, gone for his wallet');
    }

    /**
     * A cart picked up tomorrow shows tomorrow's shelf.
     *
     * Only the product, the quantity and the price were kept; stock and cost
     * are worked out again, because both move while a cart sits. Keeping them
     * would show a photograph of a shelf that has since changed.
     */
    public function test_resuming_rebuilds_the_lines_against_the_shelf_as_it_is_now(): void
    {
        $this->hold()->assertOk();

        // Somebody sells four of them while the cart is down.
        app(\App\Services\SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 3_000]],
            user: $this->admin,
            saleDate: now(),
            amountPaid: 12_000,
        );

        $this->actingAs($this->admin)
            ->get(route('sales.create', ['held' => HeldCart::sole()->id]))
            ->assertOk()
            ->assertViewHas('cartLines', function (array $lines) {
                // Six left, not the ten there were when the cart went down.
                return count($lines) === 1
                    && $lines[0]['stock'] === 6
                    && $lines[0]['quantity'] === 4;
            });
    }

    /** A product deleted while the cart sat drops out rather than breaking it. */
    public function test_a_line_whose_product_has_gone_is_dropped(): void
    {
        $this->hold()->assertOk();

        $this->product->delete();

        $this->actingAs($this->admin)
            ->get(route('sales.create', ['held' => HeldCart::sole()->id]))
            ->assertOk()
            ->assertViewHas('cartLines', fn (array $lines) => $lines === []);
    }

    /**
     * Spent when the sale is saved, and not when it is picked up.
     *
     * Picking a cart up and then walking away from it must leave it waiting;
     * losing it at that point is exactly the accident this feature prevents.
     */
    public function test_the_hold_survives_being_resumed_and_abandoned(): void
    {
        $this->hold()->assertOk();
        $id = HeldCart::sole()->id;

        $this->actingAs($this->admin)->get(route('sales.create', ['held' => $id]))->assertOk();

        $this->assertDatabaseHas('held_carts', ['id' => $id]);
    }

    public function test_the_hold_is_spent_when_the_sale_is_saved(): void
    {
        $this->hold()->assertOk();
        $id = HeldCart::sole()->id;

        $this->actingAs($this->admin)->post(route('sales.store'), [
            'customer_id' => Customer::cashCustomer()->id,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 12_000,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 3_000]],
            'held_cart_id' => $id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('held_carts', ['id' => $id]);
        $this->assertSame(1, Sale::count());
    }

    public function test_a_cart_can_be_thrown_away(): void
    {
        $this->hold()->assertOk();

        $this->actingAs($this->admin)
            ->delete(route('held-carts.destroy', HeldCart::sole()))
            ->assertRedirect();

        $this->assertSame(0, HeldCart::count());
    }

    public function test_holding_needs_permission_to_make_the_document(): void
    {
        $onlooker = User::factory()->create(['role' => 'user']);

        $this->actingAs($onlooker)->postJson(route('held-carts.store'), [
            'type' => 'sale',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 3_000]],
        ])->assertForbidden();

        $this->assertSame(0, HeldCart::count());
    }

    /** An empty cart is not a cart. */
    public function test_an_empty_cart_cannot_be_held(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('held-carts.store'), ['type' => 'sale', 'lines' => []])
            ->assertStatus(422);
    }

    // ---- The purchase cart, which has a currency ------------------------

    /**
     * A resumed purchase cart must be saveable.
     *
     * Every purchase line carries the currency it was typed in, and the cart
     * posts it back with the form. The rebuild forgot to supply it, so every
     * line came back with no currency at all and the purchase was refused —
     * "The selected lines.0.entered_currency is invalid", once per line, on a
     * form that had been filled in perfectly.
     */
    public function test_a_resumed_purchase_cart_carries_a_currency_on_every_line(): void
    {
        $this->actingAs($this->admin)->postJson(route('held-carts.store'), [
            'type' => 'purchase',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'unit_price' => 1_000,
            ]],
        ])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('purchases.create', ['held' => HeldCart::sole()->id]))
            ->assertOk()
            ->assertViewHas('cartLines', fn (array $lines) => $lines[0]['currency'] === 'IQD');
    }

    /**
     * Section 6b: a line typed in dollars comes back in dollars.
     *
     * Dropping the currency would not merely fail — it would quietly re-read a
     * dollar price as dinars, and the shopkeeper would find the price had
     * changed under them.
     */
    public function test_a_dollar_line_comes_back_in_dollars_at_the_amount_typed(): void
    {
        $this->actingAs($this->admin)->postJson(route('held-carts.store'), [
            'type' => 'purchase',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 13_200,
                'entered_currency' => 'USD',
                // Stored the way the cart stores it: dollars times a hundred.
                'entered_amount' => 1_000,
            ]],
        ])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('purchases.create', ['held' => HeldCart::sole()->id]))
            ->assertOk()
            ->assertViewHas('cartLines', function (array $lines) {
                return $lines[0]['currency'] === 'USD'
                    && (float) $lines[0]['enteredAmount'] === 10.0
                    && $lines[0]['price'] === 13_200;
            });
    }

    // ---- Somebody new, without leaving the cart -------------------------

    public function test_a_customer_can_be_added_from_the_cart_without_losing_it(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('customers.store'), ['name' => 'Rebin', 'phone' => '0770 000 1111'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name']);

        $this->assertDatabaseHas('customers', ['name' => 'Rebin']);
    }

    public function test_a_supplier_can_be_added_from_the_cart_without_losing_it(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('suppliers.store'), ['name' => 'Baghdad Imports'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name']);

        $this->assertDatabaseHas('suppliers', ['name' => 'Baghdad Imports']);
    }

    /** The screens themselves still get a redirect, not JSON. */
    public function test_the_ordinary_form_still_redirects(): void
    {
        $this->actingAs($this->admin)
            ->post(route('customers.store'), ['name' => 'Walked In'])
            ->assertRedirect();
    }
}
