<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\ExchangeService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\SwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * One counter for everything that comes back — Soran, 2026-09-25.
 *
 * *"i want one page for all but at deferent document number PRT, SRT, SWP ...
 * should have qty to both PRT and SRT, i sale 3 charger then customer return 1
 * because dont need this 1"*.
 */
class GoodsBackTest extends TestCase
{
    use RefreshDatabase;

    private Product $charger;

    private Product $bank;

    private Customer $karwan;

    private Supplier $rasan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $category = Category::first();

        $make = fn (string $sku, string $name, int $buy, int $sell) => Product::create([
            'name' => $name, 'kind' => Product::KIND_STOCK, 'sku' => $sku, 'barcode' => $sku.'-B',
            'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => $buy, 'sale_price' => $sell, 'quantity' => 0,
        ]);

        $this->charger = $make('CHG-33W', 'Charger 33W', 11_000, 18_000);
        $this->bank = $make('PD-17-UK', 'Power bank', 40_000, 60_000);

        $this->rasan = Supplier::create(['name' => 'Rasan', 'phone' => '0770', 'is_active' => true]);
        $this->karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * A shop assistant holding exactly the keys named and nothing else.
     *
     * @param  list<string>  $keys
     */
    private function assistant(string $email, array $keys): User
    {
        $user = User::create([
            'name' => 'Assistant', 'email' => $email, 'password' => 'password',
            'role' => User::ROLE_USER, 'is_active' => true,
            'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);

        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));

        return $user->load('permissions');
    }

    private function buy(Product $product, int $quantity, int $price, int $daysAgo = 7): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->rasan,
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $price,
        );
    }

    private function sell(Product $product, int $quantity, int $price, int $paid = 0): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->karwan,
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->user(), saleDate: today(), amountPaid: $paid, paymentMethod: 'cash',
        );
    }

    /** Soran's own case: three chargers sold, one coming back. */
    private function threeChargers(): SaleItem
    {
        $this->buy($this->charger, 40, 11_000);

        return $this->sell($this->charger, 3, 18_000)->items()->firstOrFail();
    }

    // ---- The page itself ---------------------------------------------------

    /**
     * ⚠️ All three states RENDERED, which is the only thing that catches a
     * Blade trap: a compiled view that dies on a nested bracket passes every
     * test that never asks for the HTML.
     */
    public function test_the_page_opens_in_each_of_its_three_states(): void
    {
        $line = $this->threeChargers();

        $this->actingAs($this->user())->get(route('goods-back.index'))
            ->assertOk()->assertSee('Which item came back?');

        $this->actingAs($this->user())->get(route('goods-back.index', ['product' => $this->charger->id]))
            ->assertOk()->assertSee('Sold to a customer')->assertSee('Bought from a supplier');

        foreach (['same', 'other', 'money'] as $answer) {
            $this->actingAs($this->user())
                ->get(route('goods-back.index', ['sale_item' => $line->id, 'answer' => $answer]))
                ->assertOk();
        }

        $bought = PurchaseItem::where('product_id', $this->charger->id)->firstOrFail();

        $this->actingAs($this->user())->get(route('goods-back.index', ['purchase_item' => $bought->id]))
            ->assertOk()->assertSee('Send it back to the supplier');
    }

    /** The suggestion answers the question before it is chosen. */
    public function test_the_search_says_what_is_possible_with_each_product(): void
    {
        $this->threeChargers();

        $rows = $this->actingAs($this->user())
            ->getJson(route('goods-back.suggest', ['q' => 'charger']))
            ->assertOk()->json('groups.0.items');

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('3 sold can come back', $rows[0]['note']);
        $this->assertStringContainsString('40 bought can go back', $rows[0]['note']);
    }

    /** ⚠️ Soran, 2026-09-25: *"no service"*. */
    public function test_a_service_is_never_offered(): void
    {
        Product::create([
            'name' => 'Screen fitting', 'kind' => Product::KIND_SERVICE, 'sku' => 'SRV-1',
            'category_id' => Category::first()->id, 'unit' => 'job',
            'purchase_price' => 0, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        $this->assertSame([], $this->actingAs($this->user())
            ->getJson(route('goods-back.suggest', ['q' => 'fitting']))->json('groups'));

        $this->actingAs($this->user())->get(route('goods-back.index', ['q' => 'fitting']))
            ->assertOk()->assertDontSee('Screen fitting');
    }

    /**
     * ⚠️ **An impossible answer is greyed with its reason, never hidden** —
     * ported here when the old swap screen was removed, because this is the
     * only page that asks the question now. A shopkeeper who cannot see the
     * Swap card does not learn that the shelf is empty.
     */
    public function test_an_empty_shelf_greys_the_swap_and_says_why(): void
    {
        $purchase = $this->buy($this->charger, 1, 11_000);
        $line = $this->sell($this->charger, 1, 18_000)->items()->firstOrFail();

        $this->assertSame(0, (int) $this->charger->fresh()->quantity, 'the shelf must be empty');
        $this->assertNotNull($purchase);

        $this->actingAs($this->user())
            ->get(route('goods-back.index', ['sale_item' => $line->id, 'answer' => 'same']))
            ->assertOk()
            // The card is still drawn, and carries the sentence that says what
            // to do instead.
            ->assertSee('The same thing again')
            ->assertSee('There is no Charger 33W left to swap it for')
            ->assertDontSee('Swap it</button>', false);
    }

    /**
     * ⚠️ A line already swapped has nothing left to come back — also ported
     * from the screen this one replaced.
     */
    public function test_a_line_already_swapped_is_not_offered_again(): void
    {
        $this->buy($this->charger, 3, 11_000);
        $line = $this->sell($this->charger, 1, 18_000)->items()->firstOrFail();

        app(SwapService::class)->create($line, 1, $this->user());

        $this->assertSame(0, $line->fresh()->returnableQuantity());

        $this->actingAs($this->user())
            ->get(route('goods-back.index', ['product' => $this->charger->id]))
            ->assertOk()
            ->assertSee('Never sold, or every line has already come back');
    }

    // ---- His money back, with a quantity -----------------------------------

    /** ⚠️ *"i sale 3 charger then customer return 1 because dont need this 1"*. */
    public function test_one_of_three_comes_back_and_only_one(): void
    {
        $line = $this->threeChargers();

        $this->actingAs($this->user())->post(route('goods-back.refund'), [
            'sale_item_id' => $line->id,
            'quantity' => 1,
            'payment_method' => 'cash',
        ])->assertSessionHas('success');

        $line->refresh();

        $this->assertSame(1, $line->quantity_returned, 'the whole line came back, not the one unit');
        $this->assertSame(2, $line->returnableQuantity());
        $this->assertSame(18_000, (int) SaleReturn::firstOrFail()->total_amount);
        $this->assertSame(38, (int) $this->charger->fresh()->quantity, '40 bought, 3 sold, 1 back');

        // 3 × 18,000 owed, less the one that came back.
        $this->assertSame(36_000, (int) $this->karwan->fresh()->balance);

        $this->assertSame(0, PurchaseReturn::count(), 'nothing goes to a supplier for an unwanted item');
    }

    /** The faulty tick, and only the faulty tick, bills the supplier. */
    public function test_faulty_sends_it_on_to_the_supplier_in_the_same_breath(): void
    {
        $line = $this->threeChargers();

        $this->actingAs($this->user())->post(route('goods-back.refund'), [
            'sale_item_id' => $line->id,
            'quantity' => 2,
            'payment_method' => 'cash',
            'faulty' => 1,
        ])->assertSessionHas('success');

        $supplierReturn = PurchaseReturn::firstOrFail();

        $this->assertSame(22_000, (int) $supplierReturn->total_amount, 'two at what Rasan was paid');
        // 40 bought, 3 sold, 2 back on the shelf, 2 straight out to Rasan.
        $this->assertSame(37, (int) $this->charger->fresh()->quantity);
    }

    // ---- A different product -----------------------------------------------

    /** ⚠️ Soran's own arithmetic: 18,000 back, 60,000 out, he pays 42,000. */
    public function test_a_dearer_product_leaves_the_difference_on_his_account(): void
    {
        $line = $this->threeChargers();
        $this->buy($this->bank, 10, 44_000);

        $this->actingAs($this->user())->post(route('goods-back.exchange'), [
            'sale_item_id' => $line->id,
            'quantity' => 1,
            'product_id' => $this->bank->id,
            'wanted_quantity' => 1,
            'unit_price' => 60_000,
            'amount_paid' => 0,
            'payment_method' => 'cash',
        ])->assertSessionHas('success');

        // 54,000 owed on the chargers, plus 60,000, less 18,000 back.
        $this->assertSame(96_000, (int) $this->karwan->fresh()->balance);
        $this->assertSame(1, $line->fresh()->quantity_returned);
        $this->assertSame(9, (int) $this->bank->fresh()->quantity);
        $this->assertSame(2, Sale::count(), 'the new invoice is its own document');
        $this->assertSame(1, SaleReturn::count());
    }

    /** Cheaper the other way: the shop hands the difference back. */
    public function test_a_cheaper_product_pays_the_difference_out(): void
    {
        $this->buy($this->bank, 5, 40_000);
        $line = $this->sell($this->bank, 1, 60_000, 60_000)->items()->firstOrFail();

        $this->buy($this->charger, 40, 11_000);

        $this->assertSame(0, (int) $this->karwan->fresh()->balance, 'he paid for the power bank');

        $this->actingAs($this->user())->post(route('goods-back.exchange'), [
            'sale_item_id' => $line->id,
            'quantity' => 1,
            'product_id' => $this->charger->id,
            'wanted_quantity' => 1,
            'unit_price' => 18_000,
            'amount_paid' => 0,
            'payment_method' => 'cash',
        ])->assertSessionHas('success');

        $this->assertSame(0, (int) $this->karwan->fresh()->balance, 'the difference does not sit on his account');

        // 18,000 charged then 60,000 credited: 42,000 could not fit, so it
        // left the till.
        $this->assertSame(42_000, (int) SaleReturn::firstOrFail()
            ->payments()->sum('amount'), 'the shop handed back the difference');
    }

    /** Same price both ways: nothing changes hands at all. */
    public function test_an_equal_swap_settles_at_nothing(): void
    {
        $line = $this->threeChargers();
        $this->buy($this->bank, 5, 44_000);

        $done = app(ExchangeService::class)->create(
            saleItem: $line, quantity: 1,
            wanted: $this->bank, wantedQuantity: 1, wantedPrice: 18_000,
            user: $this->user(), on: today(),
        );

        $this->assertSame(0, $done['settlement']);
        $this->assertSame(54_000, (int) $this->karwan->fresh()->balance, 'exactly what the chargers left');
    }

    /**
     * ⚠️ The same product is a swap, and routing it here would change an
     * invoice that had no reason to change.
     */
    public function test_exchanging_for_the_same_product_is_refused(): void
    {
        $line = $this->threeChargers();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('swap it instead');

        app(ExchangeService::class)->create(
            saleItem: $line, quantity: 1,
            wanted: $this->charger, wantedQuantity: 1, wantedPrice: 18_000,
            user: $this->user(), on: today(),
        );
    }

    /**
     * ⚠️ **A walk-in settles at the counter.** Section 4 refuses a system
     * customer who has not paid in full, so the till takes the new price in
     * and gives the old one back — netting to the difference the screen showed.
     */
    public function test_a_walk_in_settles_in_full_and_nets_to_the_difference(): void
    {
        $cash = Customer::cashCustomer();
        $this->buy($this->charger, 40, 11_000);
        $this->buy($this->bank, 5, 44_000);

        $sale = app(SaleService::class)->create(
            customer: $cash,
            lines: [['product_id' => $this->charger->id, 'quantity' => 1, 'unit_price' => 18_000]],
            user: $this->user(), saleDate: today(), amountPaid: 18_000, paymentMethod: 'cash',
        );

        app(ExchangeService::class)->create(
            saleItem: $sale->items()->firstOrFail(), quantity: 1,
            wanted: $this->bank, wantedQuantity: 1, wantedPrice: 60_000,
            user: $this->user(), on: today(),
        );

        $this->assertSame(0, (int) $cash->fresh()->balance, 'a walk-in never carries a balance');

        $in = (int) Sale::where('id', '!=', $sale->id)->firstOrFail()->payments()->sum('amount');
        $out = (int) SaleReturn::firstOrFail()->payments()->sum('amount');

        $this->assertSame(60_000, $in);
        $this->assertSame(18_000, $out);
        $this->assertSame(42_000, $in - $out, 'the drawer is up by exactly the difference');
    }

    // ---- The same thing again ----------------------------------------------

    public function test_a_swap_takes_a_quantity_and_leaves_the_invoice_alone(): void
    {
        $this->buy($this->bank, 2, 40_000, 20);
        $this->buy($this->bank, 10, 44_000, 9);
        $line = $this->sell($this->bank, 2, 60_000, 120_000)->items()->firstOrFail();

        $this->actingAs($this->user())->post(route('goods-back.swap'), [
            'sale_item_id' => $line->id,
            'quantity' => 2,
        ])->assertSessionHas('success');

        $line->refresh();

        $this->assertSame(2, $line->quantity, 'the invoice line is untouched');
        $this->assertSame(0, $line->quantity_returned);
        $this->assertSame(2, $line->quantity_swapped);
        $this->assertSame(0, $line->returnableQuantity(), 'a swapped unit cannot also be returned');

        // Both off the 40,000 layer, both replaced off the 44,000 one.
        $this->assertSame(8_000, Swap::firstOrFail()->cost());
    }

    // ---- Back to the supplier ----------------------------------------------

    public function test_a_supplier_return_takes_a_quantity_off_that_batch(): void
    {
        $purchase = $this->buy($this->charger, 40, 11_000);
        $item = $purchase->items()->firstOrFail();

        $this->actingAs($this->user())->post(route('goods-back.send-back'), [
            'purchase_item_id' => $item->id,
            'quantity' => 5,
            'payment_method' => 'cash',
        ])->assertSessionHas('success');

        $this->assertSame(55_000, (int) PurchaseReturn::firstOrFail()->total_amount);
        $this->assertSame(35, (int) $this->charger->fresh()->quantity);
        $this->assertSame(5, $item->fresh()->quantity_returned);
    }

    /**
     * ⚠️ **Two caps, and the smaller one wins.** A purchase line that has sold
     * out still counts ten unreturned on its own record; the batch it created
     * is empty. The page offers nothing and says which one bit.
     */
    public function test_an_empty_batch_is_shown_and_refused_rather_than_hidden(): void
    {
        $purchase = $this->buy($this->charger, 3, 11_000);
        $this->sell($this->charger, 3, 18_000, 54_000);

        $item = $purchase->items()->firstOrFail();

        $this->assertSame(3, $item->returnableQuantity(), 'its own counter still says three');

        $this->actingAs($this->user())
            ->get(route('goods-back.index', ['purchase_item' => $item->id]))
            ->assertOk()
            ->assertSee('That batch is empty');

        $this->actingAs($this->user())->post(route('goods-back.send-back'), [
            'purchase_item_id' => $item->id,
            'quantity' => 1,
            'payment_method' => 'cash',
        ])->assertSessionHas('error');

        $this->assertSame(0, PurchaseReturn::count());
    }

    // ---- Who may see what ---------------------------------------------------

    /**
     * ⚠️ One counter can start three documents, so a shopkeeper who may only
     * send goods back to a supplier still needs the door — and must not be
     * shown the two cards they could never press.
     */
    public function test_a_reader_is_shown_only_the_answers_they_may_give(): void
    {
        $line = $this->threeChargers();

        $user = $this->assistant('bench@example.com', [
            'purchase_returns.create', 'purchase_returns.view',
        ]);

        /*
         * ⚠️ Asserted on the BUTTONS, not on the words. The screen's own `?`
         * help explains all three answers to whoever opens it — that is the
         * point of help — so a test that looked for the phrase "the same thing
         * again" started failing the day the help arrived, on a page that was
         * behaving perfectly.
         */
        $page = $this->actingAs($user)->get(route('goods-back.index', ['sale_item' => $line->id]));

        $page->assertOk()
            ->assertDontSee(__('Swap it'))
            ->assertDontSee(__('Take it back'))
            ->assertDontSee(__('Exchange it'));
    }

    public function test_somebody_with_none_of_the_three_keys_is_refused(): void
    {
        $user = $this->assistant('nobody@example.com', ['products.view']);

        $this->actingAs($user)->get(route('goods-back.index'))->assertForbidden();
    }
}
