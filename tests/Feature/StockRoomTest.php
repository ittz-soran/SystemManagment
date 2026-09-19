<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockRoom;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DataIntegrityService;
use App\Services\FifoService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock rooms — Soran, 2026-09-15.
 *
 * *"Store, one shop with several stoke-rooms or storage rooms"*, then the rule
 * that shapes the whole thing: *"No pos or sale always user mainstore or
 * mainstorage while sale, second storage just holds that products are can hold
 * in main storage, and when purchased book at main storage then do transfer to
 * another storage"*.
 *
 * ⚠️ **This feature touches the FIFO engine, which is the books.** So most of
 * what follows is not about rooms at all — it is about proving that adding them
 * changed nothing that was already true: costs still travel with the goods,
 * oldest still goes first, and every integrity check the shop runs on itself
 * still passes afterwards.
 */
class StockRoomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Customer $customer;

    private StockRoom $main;

    private StockRoom $back;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->main = StockRoom::main();

        $this->back = StockRoom::create(['name' => 'Back room', 'is_main' => false, 'is_active' => true]);

        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 50_000, 'quantity' => 0,
        ]);

        $this->customer = Customer::create(['name' => 'C']);
    }

    // =====================================================================
    // Where stock lives
    // =====================================================================

    /** Every shop has exactly one room that sells, from the day it is upgraded. */
    public function test_a_shop_starts_with_one_main_room_holding_everything(): void
    {
        $this->assertTrue($this->main->is_main);
        $this->assertSame(1, StockRoom::where('is_main', true)->count());
    }

    /**
     * ⚠️ Goods arrive in the main room. Soran: *"when purchased book at main
     * storage then do transfer to another storage"*.
     */
    public function test_a_purchase_lands_in_the_main_room(): void
    {
        $batch = $this->buy(10, 10_000);

        $this->assertSame($this->main->id, $batch->room_id);
    }

    // =====================================================================
    // Only one room sells
    // =====================================================================

    /**
     * ⚠️ The rule the whole feature turns on.
     *
     * A till that could reach into a back room would let somebody sell a thing
     * they then cannot hand over — and they have already taken the money.
     */
    public function test_a_sale_cannot_reach_into_a_back_room(): void
    {
        $this->buy(10, 10_000);
        $this->move(8, $this->main, $this->back);

        // The shop owns ten. Two of them are sellable.
        $this->assertSame(10, $this->product->fresh()->quantity);
        $this->assertSame(2, $this->heldIn($this->main));
        $this->assertSame(8, $this->heldIn($this->back));

        $this->expectException(InsufficientStockException::class);

        $this->sell(3);
    }

    /** And it says where the rest of it is, rather than sending somebody to count a shelf. */
    public function test_the_refusal_says_the_goods_are_in_another_room(): void
    {
        $this->buy(10, 10_000);
        $this->move(8, $this->main, $this->back);

        try {
            $this->sell(3);
            $this->fail('A sale drew from a back room.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('8', $e->getMessage());
            $this->assertStringContainsString('other rooms', $e->getMessage());
        }
    }

    /** A shop with one room sees the message it has always seen. */
    public function test_a_one_room_shop_is_told_nothing_about_rooms(): void
    {
        $this->buy(2, 10_000);

        try {
            $this->sell(3);
            $this->fail('Sold more than the shop had.');
        } catch (InsufficientStockException $e) {
            $this->assertStringNotContainsString('other rooms', $e->getMessage());
        }
    }

    /**
     * ⚠️ What the shop OWNS is every room, and that is Soran's answer 3: the
     * reorder level stays on what the shop owns. A shop with forty in the back
     * has not run out, and an order raised because the shopfront shelf is empty
     * is an order for goods already paid for.
     */
    public function test_what_the_shop_owns_counts_every_room(): void
    {
        $this->buy(10, 10_000);
        $this->move(8, $this->main, $this->back);

        $this->assertSame(10, $this->product->fresh()->quantity);
    }

    // =====================================================================
    // What a transfer does to the books
    // =====================================================================

    /**
     * ⚠️ A transfer SPLITS a layer rather than moving one.
     *
     * The obvious build is `UPDATE stock_batches SET room_id = ?`, and it is
     * wrong: a partial transfer — eight out of ten — has no single room the row
     * can be in. Moving it carries all ten; not moving it loses the eight.
     */
    public function test_a_partial_transfer_splits_the_layer_and_keeps_its_cost(): void
    {
        $bought = $this->buy(10, 17_500);

        $this->move(4, $this->main, $this->back);

        $source = $bought->fresh();
        $carried = StockBatch::where('room_id', $this->back->id)->firstOrFail();

        $this->assertSame(6, $source->quantity_remaining, 'The source layer kept what stayed behind.');
        $this->assertSame(4, $carried->quantity_remaining);

        // ⚠️ Nothing is re-valued by being carried across a yard.
        $this->assertSame(17_500, $carried->unit_cost);

        // ⚠️ And it keeps the date it was BOUGHT, not today. Otherwise carrying
        // old stock to the back room would make it the newest thing there, and
        // the next sale from that room would draw the wrong cost.
        $this->assertSame(
            $source->received_at->format('Y-m-d H:i:s.u'),
            $carried->received_at->format('Y-m-d H:i:s.u'),
        );

        $this->assertSame($source->id, $carried->parent_batch_id, 'The chain back to the purchase is broken.');
    }

    /** Oldest goes to the back room first, exactly as it would go to a customer. */
    public function test_a_transfer_carries_the_oldest_stock_first(): void
    {
        $this->buy(3, 10_000, now()->subDays(3));
        $this->buy(3, 20_000, now()->subDays(2));

        $this->move(4, $this->main, $this->back);

        $carried = StockBatch::where('room_id', $this->back->id)->orderBy('id')->get();

        $this->assertSame(
            [[3, 10_000], [1, 20_000]],
            $carried->map(fn ($b) => [$b->quantity_in, $b->unit_cost])->all(),
        );
    }

    /**
     * ⚠️ The pair of movements sums to zero.
     *
     * This is what keeps `SUM(quantity)` per product equal to current stock —
     * the check Section 4 calls the one that proves the books are intact.
     */
    public function test_a_transfer_moves_no_stock_in_or_out_of_the_shop(): void
    {
        $this->buy(10, 10_000);

        $before = (int) StockMovement::where('product_id', $this->product->id)->sum('quantity');

        $this->move(4, $this->main, $this->back);

        $after = (int) StockMovement::where('product_id', $this->product->id)->sum('quantity');

        $this->assertSame($before, $after, 'A transfer changed how much the shop owns.');
        $this->assertSame(10, $this->product->fresh()->quantity);
    }

    /** Soran's answer 2: any room to any room, without routing through the shopfront. */
    public function test_goods_move_between_two_back_rooms(): void
    {
        $far = StockRoom::create(['name' => 'Far room', 'is_main' => false, 'is_active' => true]);

        $this->buy(10, 10_000);
        $this->move(6, $this->main, $this->back);
        $this->move(6, $this->back, $far);

        $this->assertSame(0, $this->heldIn($this->back));
        $this->assertSame(6, $this->heldIn($far));
        $this->assertSame(4, $this->heldIn($this->main));
        $this->assertSame(10, $this->product->fresh()->quantity);
    }

    /** Carried back, the cost is still the cost the shop paid. */
    public function test_stock_brought_back_still_sells_at_what_it_cost(): void
    {
        $this->buy(10, 17_500);
        $this->move(10, $this->main, $this->back);
        $this->move(10, $this->back, $this->main);

        $sale = $this->sell(10);

        $cogs = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->get()
            ->sum(fn ($m) => abs($m->quantity) * $m->unit_cost);

        $this->assertSame(175_000, $cogs, 'A round trip to the back room changed what the goods cost.');
    }

    /** FIFO survives the round trip: the oldest is still the oldest. */
    public function test_fifo_survives_a_trip_to_the_back_room_and_back(): void
    {
        $this->buy(3, 10_000, now()->subDays(3));
        $this->buy(3, 30_000, now()->subDay());

        // Send the OLD stock away and bring it back, so id order and FIFO order
        // now disagree — the carried layer has the highest id and the oldest date.
        $this->move(3, $this->main, $this->back);
        $this->move(3, $this->back, $this->main);

        $sale = $this->sell(4);

        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(
            [[3, 10_000], [1, 30_000]],
            $movements->map(fn ($m) => [abs($m->quantity), $m->unit_cost])->all(),
            'After a round trip the newest stock was sold first.',
        );
    }

    // =====================================================================
    // Refusals
    // =====================================================================

    public function test_a_room_cannot_move_stock_to_itself(): void
    {
        $this->buy(10, 10_000);

        $this->expectException(RuntimeException::class);

        $this->move(1, $this->main, $this->main);
    }

    public function test_nothing_moves_into_a_closed_room(): void
    {
        $this->buy(10, 10_000);
        $this->back->forceFill(['is_active' => false])->save();

        $this->expectException(RuntimeException::class);

        $this->move(1, $this->main, $this->back->fresh());
    }

    public function test_a_room_cannot_send_what_it_does_not_hold(): void
    {
        $this->buy(2, 10_000);

        $this->expectException(InsufficientStockException::class);

        $this->move(3, $this->main, $this->back);
    }

    /**
     * ⚠️ A purchase return after the goods were carried out.
     *
     * Soran's rule. Those goods are not gone — they are one room away — but
     * they are not in the layer the supplier's return draws from, and silently
     * taking them from somewhere else would send back units the shop cannot
     * account for against that purchase.
     */
    public function test_a_purchase_return_is_refused_once_the_goods_have_been_carried_out(): void
    {
        $batch = $this->buy(10, 10_000);
        $this->move(8, $this->main, $this->back);

        $this->expectException(InsufficientStockException::class);

        DB::transaction(function () use ($batch) {
            app(FifoService::class)->deductFromBatch(
                batch: $batch->fresh(),
                quantity: 5,
                purchaseReturnId: 1,
                purchaseReturnItemId: 1,
                occurredAt: now(),
                user: $this->user,
            );
        });
    }

    // =====================================================================
    // Undoing one
    // =====================================================================

    public function test_undoing_a_transfer_puts_the_layer_back_exactly(): void
    {
        $bought = $this->buy(10, 10_000);
        $transfer = $this->move(4, $this->main, $this->back);

        app(TransferService::class)->delete($transfer, $this->user);

        $this->assertSame(10, $bought->fresh()->quantity_remaining);
        $this->assertSame(0, StockBatch::where('room_id', $this->back->id)->count());
        $this->assertSame(10, $this->product->fresh()->quantity);
        $this->assertSame(
            0,
            StockMovement::where('reference_type', StockMovement::REF_TRANSFER)->count(),
            'The movements outlived the document they belonged to.',
        );
    }

    /** And it is refused once the carried units have been sold from the far room. */
    public function test_undoing_is_refused_when_the_carried_units_are_gone(): void
    {
        $this->buy(10, 10_000);
        $transfer = $this->move(10, $this->main, $this->back);

        // Bring four back and sell them: the layer the transfer made is now
        // partly spent, so putting it back would take a batch negative.
        $this->move(4, $this->back, $this->main);
        $this->sell(4);

        /*
         * ⚠️ The MESSAGE, not just the class.
         *
         * `expectException(RuntimeException::class)` passed with the refusal
         * deleted — because removing it let the delete run on into a foreign-key
         * violation, and QueryException is a RuntimeException too. The test
         * agreed with the bug it existed to catch. Only the shop's own sentence
         * proves the shop refused.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(has|have) since been sold/');

        app(TransferService::class)->delete($transfer->fresh(), $this->user);
    }

    // =====================================================================
    // The shop's own checks still pass
    // =====================================================================

    /**
     * ⚠️ The one that matters most.
     *
     * The shop runs these checks on itself and shows the result on a screen. If
     * rooms broke any of them, the shopkeeper would be told his books were
     * corrupt — by his own system, correctly.
     */
    public function test_every_integrity_check_still_passes_after_moving_stock_about(): void
    {
        $far = StockRoom::create(['name' => 'Far room', 'is_main' => false, 'is_active' => true]);

        $this->buy(10, 10_000, now()->subDays(3));
        $this->buy(10, 20_000, now()->subDays(2));

        $this->move(12, $this->main, $this->back);
        $this->move(5, $this->back, $far);
        $this->move(3, $far, $this->main);
        $this->sell(6);

        $report = app(DataIntegrityService::class)->run();

        $unhappy = collect($report['checks'])
            ->filter(fn (array $check) => $check['failed'] > 0)
            ->map(fn (array $check) => $check['key'].': '.json_encode($check['examples']))
            ->values()
            ->all();

        $this->assertSame([], $unhappy, "The shop's own checks found corruption after stock was moved between rooms.");

        // ⚠️ And every check actually RAN. A check that fell over reports
        // "unavailable" rather than a failure, so a suite that only looked at
        // failures would call a broken check a clean bill of health.
        $this->assertSame(0, $report['unavailable'], 'A check could not run, so it says nothing either way.');
    }

    // =====================================================================
    // The screens
    // =====================================================================

    /** The rooms page says what each room is holding. */
    public function test_the_rooms_page_says_what_each_room_holds(): void
    {
        $this->buy(10, 10_000);
        $this->move(4, $this->main, $this->back);

        $this->actingAs($this->user)->get(route('stock-rooms.index'))
            ->assertOk()
            ->assertSee('Back room')
            ->assertSee('Main store');
    }

    /** A transfer can be made from the screen, end to end. */
    public function test_stock_can_be_moved_from_the_screen(): void
    {
        $this->buy(10, 10_000);

        $this->actingAs($this->user)->post(route('stock-transfers.store'), [
            'from_room_id' => $this->main->id,
            'to_room_id' => $this->back->id,
            'transferred_at' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 6]],
        ])->assertRedirect();

        $this->assertSame(6, $this->heldIn($this->back));
        $this->assertSame(4, $this->heldIn($this->main));
    }

    /**
     * ⚠️ A refusal comes back to the form with what was typed, not as a 500.
     *
     * Every refusal here is a true sentence about the shop's stock, and the
     * person reading it has a cart half filled in.
     */
    public function test_a_refused_move_returns_to_the_form_rather_than_crashing(): void
    {
        $this->buy(2, 10_000);

        $this->actingAs($this->user)->post(route('stock-transfers.store'), [
            'from_room_id' => $this->main->id,
            'to_room_id' => $this->back->id,
            'transferred_at' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, $this->heldIn($this->back), 'A refused move still moved stock.');
    }

    /**
     * ⚠️ **Soran, 2026-09-18: a move answered with 305 error messages.**
     *
     * This screen is not a cart you add to — it lists EVERY product the room
     * holds, with a quantity box on each, and posts all of them. He typed one
     * number in a room holding 306 products and got back:
     *
     *     The lines.1.quantity field must be at least 1.
     *     The lines.2.quantity field must be at least 1.
     *     … 303 more …
     *
     * A blank box means "not this one", which is the ordinary way to use a list
     * like this. Reproduced here at his exact size, because the fault only
     * shows up once the room holds more than the one product a test usually
     * makes.
     */
    public function test_a_room_full_of_untouched_rows_is_not_a_page_of_errors(): void
    {
        $this->buy(10, 10_000);

        $lines = [['product_id' => $this->product->id, 'quantity' => 3]];

        for ($i = 0; $i < 305; $i++) {
            $lines[] = ['product_id' => $this->product->id, 'quantity' => 0];
        }

        $this->actingAs($this->user)->post(route('stock-transfers.store'), [
            'from_room_id' => $this->main->id,
            'to_room_id' => $this->back->id,
            'transferred_at' => now()->toDateString(),
            'lines' => $lines,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // And it moved what was typed, not 306 lines of nothing.
        $this->assertSame(3, $this->heldIn($this->back));
        $this->assertSame(7, $this->heldIn($this->main));
        $this->assertSame(1, StockTransfer::latest('id')->firstOrFail()->items()->count());
    }

    /**
     * Touching nothing at all still has to say something useful — one sentence
     * naming what to do, not silence and not a list.
     */
    public function test_moving_nothing_says_so_once(): void
    {
        $this->buy(10, 10_000);

        $lines = [];

        for ($i = 0; $i < 20; $i++) {
            $lines[] = ['product_id' => $this->product->id, 'quantity' => 0];
        }

        $response = $this->actingAs($this->user)->post(route('stock-transfers.store'), [
            'from_room_id' => $this->main->id,
            'to_room_id' => $this->back->id,
            'transferred_at' => now()->toDateString(),
            'lines' => $lines,
        ])->assertSessionHasErrors('lines');

        $bag = $response->baseResponse->getSession()->get('errors');
        $messages = is_object($bag) ? $bag->getBag('default')->all() : array_merge(...array_values($bag['default']['messages'] ?? [[]]));

        $this->assertCount(1, $messages, 'One instruction, not one message per untouched row.');
        $this->assertStringContainsString('at least one product', $messages[0]);
        $this->assertSame(0, $this->heldIn($this->back));
    }

    /**
     * ⚠️ **The search looks inside the ROOM, not across the catalogue.**
     *
     * A transfer can only carry what is on that shelf. Offering a product the
     * room does not hold would be offering a line the engine is going to
     * refuse, one at a time — which is the thing this screen was built to
     * avoid. The quantity beside each name is what THAT room has, which is the
     * number the person is deciding against.
     */
    public function test_the_search_offers_only_what_the_room_holds(): void
    {
        $this->buy(10, 10_000);
        $this->move(4, $this->main, $this->back);

        $inMain = $this->actingAs($this->user)
            ->getJson(route('stock-transfers.stock', ['stockRoom' => $this->main->id, 'q' => 'Widget']))
            ->assertOk()->json();

        $inBack = $this->actingAs($this->user)
            ->getJson(route('stock-transfers.stock', ['stockRoom' => $this->back->id, 'q' => 'Widget']))
            ->assertOk()->json();

        $this->assertSame(6, (int) $inMain['products'][0]['units'], 'The main room should offer what is left there.');
        $this->assertSame(4, (int) $inBack['products'][0]['units'], 'The back room should offer what was carried to it.');
    }

    /** A product with nothing left in that room is not on the shelf to carry. */
    public function test_a_product_the_room_has_none_of_is_not_offered(): void
    {
        $this->buy(10, 10_000);
        $this->move(10, $this->main, $this->back);

        $this->actingAs($this->user)
            ->getJson(route('stock-transfers.stock', ['stockRoom' => $this->main->id, 'q' => 'Widget']))
            ->assertOk()
            ->assertJsonCount(0, 'products');
    }

    /** A scanned code means one product, so the screen is told to add it. */
    public function test_a_whole_code_is_an_exact_hit(): void
    {
        $this->buy(10, 10_000);

        $this->actingAs($this->user)
            ->getJson(route('stock-transfers.stock', ['stockRoom' => $this->main->id, 'q' => $this->product->sku]))
            ->assertOk()
            ->assertJsonPath('exact', true)
            ->assertJsonCount(1, 'products');

        // Half a code is a search, not a scan.
        $this->actingAs($this->user)
            ->getJson(route('stock-transfers.stock', ['stockRoom' => $this->main->id, 'q' => 'Widg']))
            ->assertOk()
            ->assertJsonPath('exact', false);
    }

    /**
     * ⚠️ **The screen must not carry a field per product in the room.**
     *
     * A room with 500 products sent more than PHP's default max_input_vars of
     * 1000, and the overflow is dropped WITHOUT A WORD — a transfer that
     * quietly moves the wrong things. The cart settled this by construction
     * rather than by guard: a row exists only once somebody has chosen the
     * product, so there is nothing to strip on the way out.
     *
     * Asserted on a room with stock in it, because a page that lists nothing
     * would pass this whatever the screen did.
     */
    public function test_the_screen_carries_no_field_per_product_in_the_room(): void
    {
        $this->buy(10, 10_000);

        $html = $this->actingAs($this->user)->get(route('stock-transfers.create'))
            ->assertOk()
            ->getContent();

        // The cart opens empty, so no line inputs are rendered at all.
        $this->assertStringNotContainsString('name="lines[', $html);
        $this->assertStringNotContainsString($this->product->name, $html);

        // What is there is a search box against this room's stock.
        $this->assertStringContainsString('id="product-search"', $html);
        $this->assertStringContainsString('data-stock-url', $html);
    }

    /** Moving between the same room is refused before anything is written. */
    public function test_the_form_refuses_one_room_to_itself(): void
    {
        $this->buy(10, 10_000);

        $this->actingAs($this->user)->post(route('stock-transfers.store'), [
            'from_room_id' => $this->main->id,
            'to_room_id' => $this->main->id,
            'transferred_at' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('to_room_id');
    }

    /**
     * ⚠️ The product page separates what the shop OWNS from what it can SELL.
     *
     * This is the number a shopkeeper is standing at the counter asking about.
     */
    public function test_the_product_page_says_what_is_sellable_and_where_the_rest_is(): void
    {
        $this->buy(10, 10_000);
        $this->move(8, $this->main, $this->back);

        $this->actingAs($this->user)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee(__('Sellable now'))
            ->assertSee(__('Where it is'))
            ->assertSee('Back room');
    }

    /** A one-room shop is told nothing about rooms on the product page. */
    public function test_a_one_room_shop_sees_no_room_furniture(): void
    {
        StockRoom::whereKey($this->back->id)->forceDelete();

        $this->buy(10, 10_000);

        $this->actingAs($this->user)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertDontSee(__('Sellable now'))
            ->assertDontSee(__('Where it is'));
    }

    /** The main room cannot be removed, however the request is made. */
    public function test_the_main_room_cannot_be_removed(): void
    {
        $this->actingAs($this->user)
            ->delete(route('stock-rooms.destroy', $this->main))
            ->assertSessionHas('error');

        $this->assertNotNull(StockRoom::find($this->main->id));
    }

    /** Nor can a room that still holds something. */
    public function test_a_room_holding_stock_cannot_be_removed(): void
    {
        $this->buy(10, 10_000);
        $this->move(4, $this->main, $this->back);

        $this->actingAs($this->user)
            ->delete(route('stock-rooms.destroy', $this->back))
            ->assertSessionHas('error');

        $this->assertNotNull(StockRoom::find($this->back->id));
    }

    /** An empty one can. */
    public function test_an_empty_room_can_be_removed(): void
    {
        $this->actingAs($this->user)
            ->delete(route('stock-rooms.destroy', $this->back))
            ->assertRedirect();

        $this->assertNull(StockRoom::find($this->back->id));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    // =====================================================================
    // A delivery that goes straight to the back room
    // =====================================================================

    /**
     * ⚠️ **Soran reversed his own rule — 2026-09-18: "add purchase directly to
     * other rooms, but sale always in main".**
     *
     * The engine recorded the earlier one: *"when purchased book at main
     * storage then do transfer to another storage"*. Goods that arrive at the
     * lock-up now say so on the purchase, instead of being booked to the shop
     * floor and carried there on paper afterwards.
     */
    public function test_a_delivery_can_be_booked_straight_into_another_room(): void
    {
        $this->actingAs($this->user)->post(route('purchases.store'), [
            'supplier_id' => Supplier::create(['name' => 'S'])->id,
            'purchase_date' => now()->toDateString(),
            'room_id' => $this->back->id,
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
        ])->assertRedirect();

        $this->assertSame(10, $this->heldIn($this->back), 'The delivery did not land in the room it named.');
        $this->assertSame(0, $this->heldIn($this->main));
    }

    /** Naming no room is the shop floor, which is what it always meant. */
    public function test_a_delivery_with_no_room_named_lands_on_the_shop_floor(): void
    {
        $this->buy(10, 1_000);

        $this->assertSame(10, $this->heldIn($this->main));
        $this->assertSame(0, $this->heldIn($this->back));
    }

    /**
     * ⚠️ **The selling side is untouched, and that is the half that matters.**
     *
     * Soran: "but sale always in main". Stock booked into a back room is stock
     * the till cannot reach until somebody carries it forward — which is the
     * reason the old rule existed, and it has not stopped being true. The
     * screen says so; the engine enforces it.
     */
    public function test_the_till_still_cannot_sell_what_is_in_the_back_room(): void
    {
        $this->actingAs($this->user)->post(route('purchases.store'), [
            'supplier_id' => Supplier::create(['name' => 'S'])->id,
            'purchase_date' => now()->toDateString(),
            'room_id' => $this->back->id,
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
        ])->assertRedirect();

        $this->expectException(InsufficientStockException::class);

        app(SaleService::class)->create(
            customer: Customer::firstOrFail(),
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 2_000]],
            user: $this->user,
            saleDate: now(),

            // ⚠️ Paid in full, or the Cash Customer rule refuses first and this
            // test passes on an exception that has nothing to do with rooms.
            amountPaid: 2_000,
        );
    }

    /**
     * Editing a purchase reverses and re-applies it, so the room has to travel
     * with it — otherwise correcting a price would quietly move the goods to
     * the shop floor.
     */
    public function test_editing_a_delivery_leaves_it_in_the_room_it_was_booked_to(): void
    {
        $supplier = Supplier::create(['name' => 'S']);

        $this->actingAs($this->user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'room_id' => $this->back->id,
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_000]],
        ])->assertRedirect();

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->user)->put(route('purchases.update', $purchase), [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'room_id' => $this->back->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 1_200]],
        ])->assertRedirect();

        $this->assertSame(10, $this->heldIn($this->back), 'Correcting the price moved the goods.');
        $this->assertSame(0, $this->heldIn($this->main));
    }

    /** One room means no question to answer, so the control is not shown. */
    public function test_a_shop_with_one_room_is_not_asked_where_the_goods_went(): void
    {
        $this->back->delete();

        $this->actingAs($this->user)->get(route('purchases.create'))
            ->assertOk()
            ->assertDontSee('name="room_id"', escape: false);
    }

    /** And a shop with two is. */
    public function test_a_shop_with_two_rooms_is_asked(): void
    {
        $this->actingAs($this->user)->get(route('purchases.create'))
            ->assertOk()
            ->assertSee('name="room_id"', escape: false)
            ->assertSee('Back room');
    }

    private function heldIn(StockRoom $room): int
    {
        return (int) StockBatch::where('product_id', $this->product->id)
            ->where('room_id', $room->id)
            ->sum('quantity_remaining');
    }

    private function move(int $quantity, StockRoom $from, StockRoom $to): StockTransfer
    {
        return app(TransferService::class)->create(
            from: $from,
            to: $to,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity]],
            transferredAt: now(),
            note: null,
            user: $this->user,
        );
    }

    private function buy(int $quantity, int $unitPrice, $date = null): StockBatch
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S'.uniqid()]),
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            user: $this->user,
            purchaseDate: $date ?? now(),
        );

        return StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->firstOrFail();
    }

    private function sell(int $quantity): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 50_000]],
            user: $this->user, saleDate: now(), amountPaid: 0,
        );
    }
}
