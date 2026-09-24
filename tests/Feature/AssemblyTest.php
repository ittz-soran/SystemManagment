<?php

namespace Tests\Feature;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssemblyService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\TradeProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Taking one thing apart, and putting several together — Soran, 2026-09-24.
 *
 * *"I purchased second hand ps5 slim digital have box and 2 controller -> I
 * purchased all at 750,000 -> today I want sale it but customer say need 1
 * controller!!"*.
 *
 * ⚠️ **The money does not move.** What comes out is worth exactly what went in.
 * Every test here is ultimately about that one sentence.
 */
class AssemblyTest extends TestCase
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
            'name' => 'PS5 Slim Digital, box and 2 controllers', 'kind' => Product::KIND_USED,
            'sku' => 'PS5-BUNDLE', 'barcode' => 'PS5-BUNDLE-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 750_000, 'sale_price' => 900_000, 'quantity' => 0,
        ]);

        $this->seller = Supplier::create(['name' => 'Walk-in seller', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buyBundle(int $quantity = 1, int $cost = 750_000): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $this->bundle->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays(3), amountPaid: $quantity * $cost,
        );
    }

    /** ⚠️ Soran's console, to the dinar. */
    public function test_a_ps5_bundle_becomes_a_console_and_two_controllers(): void
    {
        $this->buyBundle();

        $assembly = app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'PS5 Slim Digital with box', 'quantity' => 1, 'unit_cost' => 600_000, 'sale_price' => 700_000],
                ['name' => 'DualSense controller', 'quantity' => 2, 'unit_cost' => 75_000, 'sale_price' => 110_000],
            ],
            user: $this->user(),
        );

        $this->assertSame('ASM-00001', $assembly->document_no);
        $this->assertSame(750_000, $assembly->total_cost);

        // The bundle is gone and the pieces are on the shelf.
        $this->assertSame(0, $this->bundle->fresh()->quantity);

        $console = Product::where('name', 'PS5 Slim Digital with box')->firstOrFail();
        $pad = Product::where('name', 'DualSense controller')->firstOrFail();

        $this->assertSame(1, $console->quantity);
        $this->assertSame(2, $pad->quantity);

        // ⚠️ A piece of a second-hand thing is second-hand too.
        $this->assertTrue($console->isUsed());
        $this->assertTrue($pad->isUsed());

        // And each carries its own real cost.
        $this->assertSame(600_000, (int) $console->stockBatches()->value('unit_cost'));
        $this->assertSame(75_000, (int) $pad->stockBatches()->value('unit_cost'));
    }

    /** ⚠️ The check the whole document exists for. */
    public function test_the_pieces_must_be_worth_exactly_what_went_in(): void
    {
        $this->buyBundle();

        try {
            app(AssemblyService::class)->takeApart(
                whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
                pieces: [
                    ['name' => 'Console', 'quantity' => 1, 'unit_cost' => 600_000],
                    // One dinar of profit, invented by typing.
                    ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => 75_001],
                ],
                user: $this->user(),
            );
            $this->fail('a dinar was invented out of nothing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('worth exactly what went in', $e->getMessage());
        }

        // ⚠️ And nothing was half-done: the bundle is still whole.
        $this->assertSame(1, $this->bundle->fresh()->quantity);
        $this->assertSame(0, Assembly::count());
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)->count());
    }

    /** ⚠️ Nothing is earned or lost by opening a box. */
    public function test_taking_something_apart_touches_neither_stock_value_nor_profit(): void
    {
        $this->buyBundle();

        $before = (int) StockBatch::sum(StockBatch::raw('quantity_remaining * unit_cost'));
        $window = [now()->subYear(), now()->addDay()];
        $profitBefore = TradeProfit::between(Product::query(), ...$window);

        app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Console', 'quantity' => 1, 'unit_cost' => 600_000],
                ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => 75_000],
            ],
            user: $this->user(),
        );

        $after = (int) StockBatch::sum(StockBatch::raw('quantity_remaining * unit_cost'));

        $this->assertSame($before, $after, 'the shelf changed value by being taken apart');
        $this->assertSame($profitBefore, TradeProfit::between(Product::query(), ...$window));
    }

    /** ⚠️ And the point of it all: sell the console and one pad, keep the other. */
    public function test_the_pieces_sell_separately_at_their_own_cost(): void
    {
        $this->buyBundle();

        app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Console', 'quantity' => 1, 'unit_cost' => 600_000, 'sale_price' => 700_000],
                ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => 75_000, 'sale_price' => 110_000],
            ],
            user: $this->user(),
        );

        $console = Product::where('name', 'Console')->firstOrFail();
        $pad = Product::where('name', 'Controller')->firstOrFail();

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [
                ['product_id' => $console->id, 'quantity' => 1, 'unit_price' => 700_000],
                ['product_id' => $pad->id, 'quantity' => 1, 'unit_price' => 110_000],
            ],
            user: $this->user(), saleDate: now(), amountPaid: 810_000, paymentMethod: 'cash',
        );

        // One controller is still on the shelf, at its own cost, waiting.
        $this->assertSame(1, $pad->fresh()->quantity);
        $this->assertSame(0, $console->fresh()->quantity);

        // Sold 810,000 against a cost of 675,000.
        $figures = TradeProfit::between(Product::query(), now()->subYear(), now()->addDay());

        $this->assertSame(810_000, $figures['revenue']);
        $this->assertSame(675_000, $figures['cost']);
        $this->assertSame(135_000, $figures['profit']);
    }

    // ---- Putting together --------------------------------------------------

    /** ⚠️ The result's cost is what the parts cost, never what somebody types. */
    public function test_parts_become_one_machine_at_the_sum_of_their_costs(): void
    {
        $parts = [];

        foreach ([['Motherboard', 300_000], ['Graphics card', 900_000], ['Case', 100_000]] as [$name, $cost]) {
            $part = Product::create([
                'name' => $name, 'kind' => Product::KIND_STOCK,
                'sku' => strtoupper(substr($name, 0, 3)).'-1', 'barcode' => strtoupper(substr($name, 0, 3)).'-1-B',
                'category_id' => Category::first()->id, 'unit' => 'pcs',
                'purchase_price' => $cost, 'sale_price' => $cost * 2, 'quantity' => 0,
            ]);

            app(PurchaseService::class)->create(
                supplier: $this->seller,
                lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => $cost]],
                user: $this->user(), purchaseDate: now()->subDays(5), amountPaid: $cost,
            );

            $parts[] = ['product_id' => $part->id, 'quantity' => 1];
        }

        $assembly = app(AssemblyService::class)->putTogether(
            pieces: $parts,
            whole: ['name' => 'Gaming PC build #3', 'quantity' => 1, 'sale_price' => 1_800_000],
            user: $this->user(),
        );

        $this->assertSame(Assembly::TOGETHER, $assembly->direction);
        $this->assertSame(1_300_000, $assembly->total_cost);

        $pc = Product::where('name', 'Gaming PC build #3')->firstOrFail();

        $this->assertSame(1, $pc->quantity);
        $this->assertSame(1_300_000, (int) $pc->stockBatches()->value('unit_cost'));

        // And the parts are off the shelf.
        foreach ($parts as $part) {
            $this->assertSame(0, Product::find($part['product_id'])->quantity);
        }
    }

    /**
     * ⚠️ **A cost sent for the result is ignored, not honoured.**
     *
     * Putting together types no cost: the machine is worth what its parts cost,
     * and anybody able to type that figure could invent value out of nothing —
     * a 1,300,000 pile of parts becoming a 5,000,000 asset on the shelf, with
     * the difference showing up as profit the first time it sold.
     *
     * The plain tests here cannot see it, because they send no cost at all and
     * a fallback to the right answer looks identical. This one sends a wrong
     * one, and failed the sabotage they walked through.
     */
    public function test_a_cost_typed_for_the_result_is_ignored(): void
    {
        $part = Product::create([
            'name' => 'Graphics card', 'kind' => Product::KIND_STOCK, 'sku' => 'GPU-1',
            'barcode' => 'GPU-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 900_000, 'sale_price' => 1_400_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => 900_000]],
            user: $this->user(), purchaseDate: now()->subDay(), amountPaid: 900_000,
        );

        $assembly = app(AssemblyService::class)->putTogether(
            pieces: [['product_id' => $part->id, 'quantity' => 1]],
            whole: [
                'name' => 'Gaming PC build #9', 'quantity' => 1, 'sale_price' => 1_800_000,
                // Sent the way a hand-edited form would send it.
                'unit_cost' => 5_000_000,
            ],
            user: $this->user(),
        );

        $pc = Product::where('name', 'Gaming PC build #9')->firstOrFail();

        $this->assertSame(900_000, $assembly->total_cost);
        $this->assertSame(900_000, (int) $pc->stockBatches()->value('unit_cost'),
            'a cost typed into the form was believed, so the shelf is worth more than the shop paid');
    }

    /** A total that will not divide is refused rather than rounded away. */
    public function test_parts_that_do_not_divide_evenly_are_refused(): void
    {
        $part = Product::create([
            'name' => 'Odd part', 'kind' => Product::KIND_STOCK, 'sku' => 'ODD-1',
            'barcode' => 'ODD-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 1_001, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: $this->seller,
            lines: [['product_id' => $part->id, 'quantity' => 1, 'unit_price' => 1_001]],
            user: $this->user(), purchaseDate: now()->subDay(), amountPaid: 1_001,
        );

        try {
            app(AssemblyService::class)->putTogether(
                pieces: [['product_id' => $part->id, 'quantity' => 1]],
                whole: ['name' => 'Two halves', 'quantity' => 2],
                user: $this->user(),
            );
            $this->fail('a dinar was rounded away');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not divide evenly', $e->getMessage());
        }

        $this->assertSame(0, Assembly::count());
        $this->assertSame(1, $part->fresh()->quantity, 'stock moved for a build that did not happen');
    }

    /** An existing product is added to rather than duplicated. */
    public function test_a_piece_can_land_in_a_product_the_shop_already_has(): void
    {
        $this->buyBundle();

        $existing = Product::create([
            'name' => 'DualSense controller', 'kind' => Product::KIND_USED, 'sku' => 'PAD-1',
            'barcode' => 'PAD-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 70_000, 'sale_price' => 110_000, 'quantity' => 0,
        ]);

        app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Console only', 'quantity' => 1, 'unit_cost' => 600_000],
                ['product_id' => $existing->id, 'quantity' => 2, 'unit_cost' => 75_000],
            ],
            user: $this->user(),
        );

        $this->assertSame(2, $existing->fresh()->quantity);
        $this->assertSame(1, Product::where('name', 'DualSense controller')->count(), 'a second product was made');
    }

    /** ⚠️ Nothing on the shelf, nothing to take apart. */
    public function test_it_refuses_what_is_not_in_stock(): void
    {
        $this->expectException(RuntimeException::class);

        app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [['name' => 'Console', 'quantity' => 1, 'unit_cost' => 0]],
            user: $this->user(),
        );
    }

    /** The document says which way round it went, and keeps both sides. */
    public function test_the_document_keeps_both_sides(): void
    {
        $this->buyBundle();

        $assembly = app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Console', 'quantity' => 1, 'unit_cost' => 600_000],
                ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => 75_000],
            ],
            user: $this->user(),
        );

        $this->assertTrue($assembly->isApart());
        $this->assertSame(1, $assembly->sources()->count());
        $this->assertSame(2, $assembly->results()->count());
        $this->assertSame($this->bundle->id, $assembly->whole()->product_id);
        $this->assertSame(2, $assembly->pieces()->count());

        $this->assertSame(750_000, (int) $assembly->sourceLines()->sum(fn (AssemblyItem $i) => $i->lineTotal()));
        $this->assertSame(750_000, (int) $assembly->resultLines()->sum(fn (AssemblyItem $i) => $i->lineTotal()));
    }

    // ---- Sharing the cost out ---------------------------------------------

    /** ⚠️ Soran's bundle: it must land exactly, so the form can be saved. */
    public function test_sharing_out_lands_exactly_on_the_total(): void
    {
        $costs = app(AssemblyService::class)->shareOut(collect([
            ['value' => 700_000, 'quantity' => 1],   // the console
            ['value' => 110_000, 'quantity' => 2],   // two controllers
        ]), 750_000);

        $this->assertSame(750_000, $costs[0] * 1 + $costs[1] * 2, 'the shares do not add up to the whole');
        $this->assertGreaterThan($costs[1], $costs[0], 'the console should carry more than a controller');
    }

    /**
     * ⚠️ **It lands exactly, for every awkward total, whenever it can.**
     *
     * A line of two carries one cost for both units, so it can only absorb even
     * amounts; a form left a dinar short is one the balance check refuses, and
     * the shopkeeper would be handed a screen that will not save with no idea
     * why. One example proves nothing here — the arithmetic goes wrong only on
     * particular remainders — so this walks two hundred of them.
     *
     * ⚠️ A first version of this test checked a single total and passed while
     * the remainder was being put on the WRONG line, because both choices
     * happened to balance for those numbers.
     */
    public function test_it_lands_exactly_on_every_total_when_a_single_piece_can_take_it(): void
    {
        $service = app(AssemblyService::class);

        $shapes = [
            [['value' => 100, 'quantity' => 2], ['value' => 100, 'quantity' => 1]],
            [['value' => 700_000, 'quantity' => 1], ['value' => 110_000, 'quantity' => 2]],
            [['value' => 1, 'quantity' => 3], ['value' => 1, 'quantity' => 1]],
            [['value' => 0, 'quantity' => 5], ['value' => 900, 'quantity' => 1], ['value' => 50, 'quantity' => 7]],
        ];

        foreach ($shapes as $shape) {
            $lines = collect($shape);

            for ($total = 1; $total <= 200; $total++) {
                $costs = $service->shareOut($lines, $total);

                $placed = $lines->sum(fn (array $line, $key) => $costs[$key] * $line['quantity']);

                $this->assertSame($total, $placed,
                    "a total of {$total} did not land exactly, and the form would refuse to save");

                foreach ($costs as $cost) {
                    $this->assertGreaterThanOrEqual(0, $cost, 'a negative cost was shared out');
                }
            }
        }
    }

    /** With no prices at all, every unit is weighted the same. */
    public function test_sharing_out_with_no_prices_weighs_every_unit_alike(): void
    {
        $costs = app(AssemblyService::class)->shareOut(collect([
            ['value' => 0, 'quantity' => 1],
            ['value' => 0, 'quantity' => 1],
            ['value' => 0, 'quantity' => 1],
        ]), 1_000);

        $this->assertSame(1_000, $costs->sum());
    }

    /**
     * ⚠️ When it cannot land exactly, it stops short rather than losing a
     * dinar. Every line has a quantity above one and the total is odd, so no
     * arrangement of per-unit costs can reach it — the screen shows what is
     * left and the shopkeeper places it.
     */
    public function test_what_cannot_be_shared_is_left_outstanding_rather_than_lost(): void
    {
        $costs = app(AssemblyService::class)->shareOut(collect([
            ['value' => 100, 'quantity' => 2],
            ['value' => 100, 'quantity' => 4],
        ]), 1_001);

        $placed = $costs[0] * 2 + $costs[1] * 4;

        $this->assertLessThan(1_001, $placed);
        $this->assertGreaterThan(1_001 - 2, $placed, 'more was left outstanding than had to be');
    }

    /** And the button's figures really do save. */
    public function test_the_shared_costs_pass_the_balance_check(): void
    {
        $this->buyBundle();

        $costs = app(AssemblyService::class)->shareOut(collect([
            ['value' => 700_000, 'quantity' => 1],
            ['value' => 110_000, 'quantity' => 2],
        ]), 750_000);

        $assembly = app(AssemblyService::class)->takeApart(
            whole: ['product_id' => $this->bundle->id, 'quantity' => 1],
            pieces: [
                ['name' => 'Console', 'quantity' => 1, 'unit_cost' => $costs[0]],
                ['name' => 'Controller', 'quantity' => 2, 'unit_cost' => $costs[1]],
            ],
            user: $this->user(),
        );

        $this->assertSame(750_000, $assembly->total_cost);
    }
}
