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

    /** The same phone is the same person, so what they are owed adds up. */
    public function test_selling_twice_from_one_phone_is_one_person(): void
    {
        $first = $this->buyUsed(['amount_paid' => 0]);
        $second = $this->buyUsed(['name' => 'Dell Latitude', 'cost' => 500_000, 'amount_paid' => 0]);

        $this->assertSame($first['seller']->id, $second['seller']->id);
        $this->assertSame(800_000, (int) $first['seller']->refresh()->balance);
        $this->assertSame(1, Supplier::walkIns()->count());
    }

    public function test_two_people_with_no_phone_stay_two_people(): void
    {
        $first = $this->buyUsed(['seller_name' => 'Ahmed', 'seller_phone' => null]);
        $second = $this->buyUsed(['seller_name' => 'Ahmed', 'seller_phone' => null]);

        $this->assertNotSame($first['seller']->id, $second['seller']->id);
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

        // The second-hand list separates what is held from what is gone.
        $this->actingAs($this->admin)->get(route('second-hand.index', ['status' => 'sold']))
            ->assertOk()->assertSee($item->name);

        $this->actingAs($this->admin)->get(route('second-hand.index', ['status' => 'in_stock']))
            ->assertOk()->assertDontSee($item->name);
    }

    public function test_a_used_item_is_kept_out_of_the_ordinary_product_list(): void
    {
        $item = $this->buyUsed()['product'];

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()->assertDontSee($item->sku);

        $this->actingAs($this->admin)->get(route('second-hand.index'))
            ->assertOk()->assertSee($item->sku);
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
