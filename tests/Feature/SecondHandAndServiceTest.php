<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SecondHandService;
use App\Services\StockAdjustmentService;
use App\Services\SystemResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The two things the shop sells that are not ordinary stock.
 *
 * A SECOND-HAND item is one physical thing bought off the street. Two Xboxes
 * are not interchangeable — different day, different money, different
 * condition — so a costing that queues or averages them would put a made-up
 * cost against a real sale. Each gets its own row holding one unit, which is
 * what makes FIFO exactly right here: one batch, nothing to choose between,
 * and the cost recorded is the money that actually left the till for that item.
 *
 * A SERVICE is sold and never stocked. Nothing is bought for it, so no batch is
 * opened and no movement is written — and since COGS is the sum of the
 * movements a sale consumed, its whole price falls through as profit without
 * anything having to say so.
 */
class SecondHandAndServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->customer = Customer::create(['name' => 'Karwan']);
    }

    /** @param array<string, mixed> $overrides */
    private function buyUsed(array $overrides = []): array
    {
        return app(SecondHandService::class)->buy([
            'name' => 'Xbox Series S 512GB',
            'condition_note' => 'One controller, no box',
            'cost' => 300_000,
            'sale_price' => 400_000,
            'seller_name' => 'Rebaz',
            'seller_phone' => '0770 111 2233',
            'amount_paid' => 300_000,
            ...$overrides,
        ], $this->admin);
    }

    private function service(int $price = 5_000): Product
    {
        return Product::create([
            'name' => 'Create an email account',
            'kind' => Product::KIND_SERVICE,
            'sku' => 'SRV-'.uniqid(),
            'category_id' => Category::firstOrCreate(['name' => 'Services'])->id,
            'unit' => 'each',
            'purchase_price' => 0,
            'sale_price' => $price,
            'quantity' => 0,
        ]);
    }

    private function sell(Product $product, int $price, int $quantity = 1): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    // ------------------------------------------------------- second-hand: buying

    public function test_buying_creates_the_item_the_seller_and_the_purchase_together(): void
    {
        $result = $this->buyUsed();

        $item = $result['product'];

        $this->assertSame(Product::KIND_USED, $item->kind);
        $this->assertSame('One controller, no box', $item->condition_note);
        $this->assertSame(1, $item->quantity, 'One thing, one unit');
        $this->assertSame(300_000, (int) $item->purchase_price);
        $this->assertSame(400_000, (int) $item->sale_price);

        // The seller is a supplier so the money works, kept off the supplier list.
        $seller = $result['seller'];
        $this->assertTrue($seller->is_walk_in);
        $this->assertSame('Rebaz', $seller->name);
        $this->assertSame(0, Supplier::companies()->where('id', $seller->id)->count());

        // And the purchase is an ordinary purchase, doing what one does.
        $this->assertSame($seller->id, $result['purchase']->supplier_id);
        $this->assertSame(300_000, (int) $result['purchase']->grand_total);

        $batches = StockBatch::where('product_id', $item->id)->get();
        $this->assertCount(1, $batches, 'One item is one batch — that is what makes its cost its own');
        $this->assertSame(300_000, (int) $batches->first()->unit_cost);
    }

    public function test_what_is_left_unpaid_is_owed_to_the_person_who_sold_it(): void
    {
        $result = $this->buyUsed(['amount_paid' => 200_000]);

        $this->assertSame(100_000, (int) $result['seller']->refresh()->balance);

        // ...and the shop can settle it later, through the ordinary screens.
        $this->actingAs($this->admin)->get(route('suppliers.show', $result['seller']))
            ->assertOk()
            ->assertSee($result['purchase']->document_no);
    }

    /**
     * Nobody is recognised behind the shopkeeper's back.
     *
     * Matching on the phone by itself was wrong twice over: it attached a buy
     * to somebody who had not been named, and since the matched record keeps
     * its own name, the second person's name simply vanished. Typing a name
     * now always makes a new person, whatever the number.
     */
    public function test_typing_a_seller_makes_a_new_person_even_on_a_repeated_number(): void
    {
        $first = $this->buyUsed(['amount_paid' => 0]);
        $second = $this->buyUsed(['name' => 'Dell Latitude', 'cost' => 500_000, 'amount_paid' => 0]);

        $this->assertNotSame($first['seller']->id, $second['seller']->id);
        $this->assertSame(2, Supplier::walkIns()->count());
    }

    /** Picking somebody from the list is what makes it them, and it adds up. */
    public function test_choosing_a_seller_reuses_them_and_their_balance_adds_up(): void
    {
        $first = $this->buyUsed(['amount_paid' => 0]);

        $second = $this->buyUsed([
            'name' => 'Dell Latitude',
            'cost' => 500_000,
            'amount_paid' => 0,
            'seller_id' => $first['seller']->id,
        ]);

        $this->assertSame($first['seller']->id, $second['seller']->id);
        $this->assertSame(800_000, (int) $first['seller']->refresh()->balance);
        $this->assertSame(1, Supplier::walkIns()->count());
    }

    /** Section 9: they are not suppliers, and the supplier list does not show them. */
    public function test_a_walk_in_seller_never_appears_on_the_supplier_list(): void
    {
        $result = $this->buyUsed();

        $this->actingAs($this->admin)->get(route('suppliers.index'))
            ->assertOk()
            ->assertDontSee($result['seller']->name);

        // Their own screen still has them, and their statement still opens.
        $this->actingAs($this->admin)->get(route('second-hand.sellers'))
            ->assertOk()
            ->assertSee($result['seller']->name);
    }

    public function test_the_seller_lookup_finds_them_by_name_and_by_number(): void
    {
        $result = $this->buyUsed();

        foreach ([$result['seller']->name, $result['seller']->phone] as $term) {
            $this->actingAs($this->admin)
                ->getJson(route('second-hand.sellers.search', ['q' => $term]))
                ->assertOk()
                ->assertJsonFragment(['id' => $result['seller']->id]);
        }
    }

    public function test_two_people_with_no_phone_stay_two_people(): void
    {
        $first = $this->buyUsed(['seller_name' => 'Ahmed', 'seller_phone' => null]);
        $second = $this->buyUsed(['seller_name' => 'Ahmed', 'seller_phone' => null]);

        $this->assertNotSame($first['seller']->id, $second['seller']->id);
    }

    /**
     * The buy screen itself, over HTTP.
     *
     * Everything else here calls the service directly, which left the form and
     * its route untested — and a controller can be broken while every one of
     * those still passes.
     */
    public function test_the_buy_screen_records_the_item_and_the_purchase(): void
    {
        $this->actingAs($this->admin)->get(route('second-hand.create'))
            ->assertOk()
            ->assertSee(__('Buy a second-hand item'));

        $this->actingAs($this->admin)->post(route('second-hand.store'), [
            'name' => 'Dell Latitude 5490',
            'condition_note' => 'i5, 8GB, scratched lid',
            'cost' => 500_000,
            'sale_price' => 620_000,
            'amount_paid' => 300_000,
            'payment_method' => 'cash',
            'seller_name' => 'Hawkar',
            'seller_phone' => '0770 999 8877',
            'bought_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('second-hand.index'));

        $item = Product::used()->where('name', 'Dell Latitude 5490')->firstOrFail();

        $this->assertSame(1, $item->quantity);
        $this->assertSame('i5, 8GB, scratched lid', $item->condition_note);
        $this->assertSame(500_000, (int) $item->stockBatches()->firstOrFail()->unit_cost);
        $this->assertSame(200_000, (int) Supplier::walkIns()->firstOrFail()->balance);
    }

    /** Paying more than the price agreed is a typo, not a deposit. */
    public function test_the_buy_screen_refuses_more_paid_than_agreed(): void
    {
        $this->actingAs($this->admin)->post(route('second-hand.store'), [
            'name' => 'Xbox',
            'cost' => 300_000,
            'sale_price' => 400_000,
            'amount_paid' => 400_000,
            'seller_name' => 'Rebaz',
            'bought_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount_paid');

        $this->assertSame(0, Product::used()->count());
    }

    /**
     * The default category is a stored name, not a label. Read through __() it
     * would find nothing in Kurdish and make a second category, and the shop
     * would end up with one of everything per language it was opened in.
     */
    public function test_the_default_category_does_not_multiply_per_language(): void
    {
        $this->actingAs($this->admin)->get(route('second-hand.create'))->assertOk();

        app()->setLocale('ckb');
        $this->actingAs($this->admin)->get(route('second-hand.create'))->assertOk();
        $this->buyUsed();

        app()->setLocale('ar');
        $this->actingAs($this->admin)->get(route('second-hand.create'))->assertOk();

        app()->setLocale('en');

        $this->assertSame(1, Category::where('name', SecondHandService::DEFAULT_CATEGORY)->count());
        $this->assertSame(1, Category::whereIn('name', [
            SecondHandService::DEFAULT_CATEGORY, __('Second-hand'),
        ])->count(), 'One category, whatever language it was opened in');
    }

    // ------------------------------------------------------- second-hand: selling

    /**
     * The heart of it. Two of the same model bought at different prices: selling
     * one must cost what THAT one cost, not what the older one did.
     */
    public function test_each_item_is_sold_at_its_own_cost_whichever_is_sold_first(): void
    {
        $cheap = $this->buyUsed(['name' => 'Xbox Series S (A)', 'cost' => 300_000])['product'];
        $dear = $this->buyUsed(['name' => 'Xbox Series S (B)', 'cost' => 380_000])['product'];

        // The newer, dearer one goes first — which under a queue would have been
        // costed at 300,000 and shown a profit 80,000 too high.
        $sale = $this->sell($dear, 400_000);

        $cogs = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('-quantity * unit_cost'));

        $this->assertSame(380_000, $cogs, 'Costed at what this machine cost, not the other one');
        $this->assertSame(0, $dear->refresh()->quantity, 'And it is gone');
        $this->assertSame(1, $cheap->refresh()->quantity, 'While the other is untouched');
    }

    public function test_a_sold_item_is_out_of_stock_for_good(): void
    {
        $item = $this->buyUsed()['product'];

        $this->sell($item, 400_000);

        $this->assertSame(0, $item->refresh()->quantity);

        // The second-hand list can separate what is held from what is gone...
        $this->actingAs($this->admin)->get(route('second-hand.index', ['status' => 'sold']))
            ->assertOk()->assertSee($item->name);

        $this->actingAs($this->admin)->get(route('second-hand.index', ['status' => 'in_stock']))
            ->assertOk()->assertDontSee($item->name);
    }

    /**
     * ...but not by default. Selling an item is the moment its row becomes
     * worth reading — what it made — and a default that hid it made a sale look
     * like the item had been lost.
     */
    public function test_selling_an_item_does_not_remove_it_from_the_book(): void
    {
        $item = $this->buyUsed()['product'];
        $sale = $this->sell($item, 400_000);

        $response = $this->actingAs($this->admin)
            ->get(route('second-hand.index'))->assertOk();

        $response->assertSee($item->name);
        $response->assertSee($sale->document_no);
        $response->assertSee('+100,000');

        // And the purchase it came in on is still named beside it.
        $purchase = $item->stockBatches()->firstOrFail();
        $this->assertStringContainsString(
            'href="'.route('purchases.show', $purchase->source_id).'"',
            $response->getContent(),
        );
    }

    public function test_a_used_item_is_kept_out_of_the_ordinary_product_list(): void
    {
        $item = $this->buyUsed()['product'];

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()->assertDontSee($item->sku);

        $this->actingAs($this->admin)->get(route('second-hand.index'))
            ->assertOk()->assertSee($item->sku);
    }

    /**
     * The figures at the top of the book.
     *
     * Money, so they are read from the batches and the movements rather than
     * from products.purchase_price, which the product form can change — the
     * same reason the per-item cost is.
     */
    public function test_the_figures_read_from_the_batches_and_the_movements(): void
    {
        // Two on the shelf: 300,000 and 500,000 paid, asked at 400,000 and 620,000.
        $held = $this->buyUsed(['cost' => 300_000, 'sale_price' => 400_000])['product'];
        $this->buyUsed(['name' => 'Dell Latitude', 'cost' => 500_000, 'sale_price' => 620_000]);

        // And one bought and sold this month for 430,000 against 380,000.
        $sold = $this->buyUsed(['name' => 'Xbox (B)', 'cost' => 380_000, 'sale_price' => 430_000])['product'];
        $this->sell($sold, 430_000);

        // A suggestion the product form can change must not move any of them.
        $held->forceFill(['purchase_price' => 999_000])->save();

        $figures = $this->actingAs($this->admin)
            ->get(route('second-hand.index'))->assertOk()->viewData('figures');

        $this->assertSame(2, $figures['held']);
        $this->assertSame(800_000, $figures['held_value'], 'The money in the shelf, from the batches');
        $this->assertSame(220_000, $figures['expected'], '1,020,000 asked less 800,000 paid');
        $this->assertSame(1, $figures['sold']);
        $this->assertSame(50_000, $figures['made'], '430,000 less what that machine cost');
        $this->assertSame(3, $figures['bought'], 'All three came in this month');
        $this->assertSame(1_180_000, $figures['spent'], 'And this much went out for them');
    }

    /** An item sold and given back made nothing, and must say so. */
    public function test_a_returned_item_nets_out_of_what_the_period_made(): void
    {
        $item = $this->buyUsed()['product'];
        $sale = $this->sell($item, 400_000);

        $before = $this->actingAs($this->admin)
            ->get(route('second-hand.index'))->viewData('figures');
        $this->assertSame(100_000, $before['made']);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $after = $this->actingAs($this->admin)
            ->get(route('second-hand.index'))->viewData('figures');

        $this->assertSame(0, $after['made'], 'Both sides came off');
        $this->assertSame(0, $after['sold']);
        $this->assertSame(1, $after['held'], 'And it is back on the shelf');
    }

    public function test_what_is_still_owed_to_sellers_is_on_the_page(): void
    {
        $this->buyUsed(['amount_paid' => 200_000]);

        $figures = $this->actingAs($this->admin)
            ->get(route('second-hand.index'))->viewData('figures');

        $this->assertSame(100_000, $figures['owed_to_sellers']);
    }

    // --------------------------------------------------------------- services

    public function test_a_service_sells_without_touching_stock(): void
    {
        $service = $this->service(5_000);

        $sale = $this->sell($service, 5_000);

        $this->assertSame(0, StockMovement::where('reference_id', $sale->id)
            ->where('reference_type', StockMovement::REF_SALE)->count(),
            'Nothing moved, because there was never anything to move');

        $this->assertSame(0, StockBatch::where('product_id', $service->id)->count());
        $this->assertSame(0, $service->refresh()->quantity);
        $this->assertSame(5_000, (int) $sale->total_amount);
    }

    /** No cost recorded means no cost to subtract: the price is the profit. */
    public function test_the_whole_price_of_a_service_is_profit(): void
    {
        $service = $this->service(5_000);

        $this->sell($service, 5_000);

        $report = $this->actingAs($this->admin)
            ->get(route('reports.index'))->assertOk()->viewData('profit');

        $this->assertSame(5_000, $report['revenue']);
        $this->assertSame(0, $report['cogs']);
        $this->assertSame(5_000, $report['gross_profit']);
    }

    public function test_returning_a_service_gives_the_money_back_and_no_stock(): void
    {
        $service = $this->service(5_000);
        $sale = $this->sell($service, 5_000);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $this->assertSame(5_000, (int) $return->total_amount);
        $this->assertSame(0, $service->refresh()->quantity, 'Nothing came back onto a shelf');
        $this->assertSame(0, StockBatch::where('product_id', $service->id)->count());
    }

    public function test_a_service_cannot_be_purchased(): void
    {
        $service = $this->service();

        try {
            app(\App\Services\PurchaseService::class)->create(
                supplier: Supplier::create(['name' => 'Bazaar Mobile']),
                lines: [['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 1_000]],
                user: $this->admin, purchaseDate: now(), amountPaid: 0,
            );
            $this->fail('A service has no stock to buy into.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('service', $e->getMessage());
        }

        $this->assertSame(0, StockBatch::where('product_id', $service->id)->count());
    }

    public function test_a_service_cannot_be_adjusted(): void
    {
        $service = $this->service();

        try {
            app(StockAdjustmentService::class)->create(
                product: $service, direction: 'in', quantity: 1,
                reason: 'miscount', user: $this->admin, unitCost: 1_000,
            );
            $this->fail('There is no stock behind a service to correct.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('service', $e->getMessage());
        }
    }

    public function test_a_service_is_kept_out_of_the_product_list_and_the_purchase_cart(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()->assertDontSee($service->sku);

        // The purchase cart's lookup cannot find one to add.
        $found = $this->actingAs($this->admin)
            ->getJson(route('products.search', ['q' => 'email', 'kinds' => 'stock']))
            ->assertOk()->json('products');

        $this->assertSame([], $found);

        // The sale cart can, because that is where it is sold.
        $found = $this->actingAs($this->admin)
            ->getJson(route('products.search', ['q' => 'email']))
            ->assertOk()->json('products');

        $this->assertCount(1, $found);
        $this->assertSame(Product::KIND_SERVICE, $found[0]['kind']);
        $this->assertNull($found[0]['next_batch_cost'], 'Nothing it can be sold below');
    }

    // ----------------------------------------- living with the rest of the system

    /**
     * A returned second-hand item is back on the shelf and can be sold again —
     * to somebody else, at another price. Its cost does not change, because the
     * money that bought it did not.
     */
    public function test_a_returned_item_goes_back_on_the_shelf_and_can_be_sold_again(): void
    {
        $item = $this->buyUsed()['product'];

        $sale = $this->sell($item, 400_000);
        $this->assertSame(0, $item->refresh()->quantity);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $this->assertSame(1, $item->refresh()->quantity, 'Back in the shop');

        $again = $this->sell($item, 360_000);

        $cogs = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $again->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('-quantity * unit_cost'));

        $this->assertSame(300_000, $cogs, 'Still what the shop paid for it');
        $this->assertSame(0, $item->refresh()->quantity);
    }

    /** Editing a sale that carries a service must not look for stock it never took. */
    public function test_a_sale_carrying_a_service_can_be_edited(): void
    {
        $service = $this->service(5_000);
        $sale = $this->sell($service, 5_000);

        app(SaleService::class)->update(
            sale: $sale,
            customer: $this->customer,
            lines: [['product_id' => $service->id, 'quantity' => 2, 'unit_price' => 5_000]],
            user: $this->admin,
            saleDate: now(),
        );

        $this->assertSame(10_000, (int) $sale->refresh()->total_amount);
        $this->assertSame(0, StockMovement::where('reference_id', $sale->id)
            ->where('reference_type', StockMovement::REF_SALE)->count());
        $this->assertSame(0, $service->refresh()->quantity);
    }

    /** Everything a second-hand item does is logged, because it is a product. */
    public function test_buying_one_is_written_to_the_activity_log(): void
    {
        // The log attributes every entry to a person, so it needs one — which
        // in the shop there always is.
        $this->actingAs($this->admin);

        $result = $this->buyUsed();

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'products',
            'record_id' => $result['product']->id,
            'action' => 'create',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'module' => 'purchases',
            'record_id' => $result['purchase']->id,
            'action' => 'create',
        ]);
    }

    /**
     * Section 8c's "start fresh". A second-hand item is a transaction wearing a
     * product's clothes: with its purchase gone it can never be sold and never
     * be bought again, so leaving it behind would fill the book with machines
     * the shop does not have. Services are catalogue and stay.
     */
    public function test_starting_fresh_clears_second_hand_items_and_keeps_services(): void
    {
        $item = $this->buyUsed()['product'];
        $service = $this->service();

        $stock = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::firstOrCreate(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $this->assertArrayHasKey('second_hand_items', app(SystemResetService::class)->preview());

        app(SystemResetService::class)->run($this->admin, 'Soran Store');

        $this->assertDatabaseMissing('products', ['id' => $item->id]);
        $this->assertDatabaseHas('products', ['id' => $service->id]);
        $this->assertDatabaseHas('products', ['id' => $stock->id]);
    }

    /** The cost shown is the batch's, so editing the product row cannot make it lie. */
    public function test_the_cost_shown_is_the_money_that_actually_left_the_till(): void
    {
        $item = $this->buyUsed(['cost' => 300_000])['product'];

        // The product form can change this suggestion; the batch is the truth.
        $item->forceFill(['purchase_price' => 999_000])->save();

        $this->actingAs($this->admin)->get(route('second-hand.index'))
            ->assertOk()
            ->assertSee('300,000')
            ->assertDontSee('999,000');
    }

    /**
     * The report separates the three trades, because they behave nothing alike:
     * stock turns over on a thin margin, a used machine is a few large bets, and
     * a service is all margin. One gross-profit figure hides which is carrying
     * the month.
     */
    public function test_the_report_shows_where_the_profit_came_from(): void
    {
        $stock = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::firstOrCreate(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        app(\App\Services\PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $stock->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $used = $this->buyUsed(['cost' => 300_000, 'sale_price' => 400_000])['product'];
        $service = $this->service(5_000);

        $this->sell($stock, 15_000, quantity: 4);
        $this->sell($used, 400_000);
        $this->sell($service, 5_000);

        $rows = collect($this->actingAs($this->admin)
            ->get(route('reports.index'))->assertOk()->viewData('byKind'))
            ->keyBy('label');

        $this->assertSame(60_000, $rows['Products']['revenue']);
        $this->assertSame(40_000, $rows['Products']['cost']);
        $this->assertSame(20_000, $rows['Products']['profit']);
        $this->assertSame(33, $rows['Products']['margin']);

        $this->assertSame(400_000, $rows['Second-hand']['revenue']);
        $this->assertSame(300_000, $rows['Second-hand']['cost']);
        $this->assertSame(100_000, $rows['Second-hand']['profit']);

        // Costs nothing to give, so all of it is profit.
        $this->assertSame(5_000, $rows['Services']['revenue']);
        $this->assertSame(0, $rows['Services']['cost']);
        $this->assertSame(5_000, $rows['Services']['profit']);
        $this->assertSame(100, $rows['Services']['margin']);

        // And the three add up to the gross profit above them.
        $profit = $this->actingAs($this->admin)
            ->get(route('reports.index'))->viewData('profit');

        $this->assertSame($profit['gross_profit'], $rows->sum('profit'));
    }

    // ------------------------------------------------------------ both at once

    /** The ordinary case at the counter: a used console and a service on one bill. */
    public function test_stock_a_used_item_and_a_service_can_share_one_sale(): void
    {
        $stock = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::firstOrCreate(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        app(\App\Services\PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $stock->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $used = $this->buyUsed()['product'];
        $service = $this->service(5_000);

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [
                ['product_id' => $stock->id, 'quantity' => 2, 'unit_price' => 15_000],
                ['product_id' => $used->id, 'quantity' => 1, 'unit_price' => 400_000],
                ['product_id' => $service->id, 'quantity' => 1, 'unit_price' => 5_000],
            ],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $this->assertSame(435_000, (int) $sale->total_amount);

        // Two lines moved stock; the service moved none.
        $this->assertSame(2, StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)->distinct('product_id')->count('product_id'));

        $cogs = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('-quantity * unit_cost'));

        // 2 × 10,000 for the flash drives, 300,000 for the console, nothing
        // for the service.
        $this->assertSame(320_000, $cogs);
        $this->assertSame(8, $stock->refresh()->quantity);
        $this->assertSame(0, $used->refresh()->quantity);
    }
}
