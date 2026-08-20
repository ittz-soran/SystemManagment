<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 10b — the acceptance test. Run this before go-live.
 *
 * Section 10 is the readable example; THIS is the test. Every value below is an
 * assertion taken from the project doc, and the whole thing runs as one ordered
 * scenario because each step depends on the state the last one left behind.
 */
class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Supplier $supplierA;

    private Supplier $supplierB;

    private Customer $customerC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();

        // Fixture: Product P, sale price 30,000 - Supplier A - Supplier B - Customer C
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Product P',
            'sku' => 'P',
            'category_id' => $category->id,
            'unit' => 'pcs',
            'purchase_price' => 0,
            'sale_price' => 30_000,
            'quantity' => 0,
        ]);

        $this->supplierA = Supplier::create(['name' => 'Supplier A']);
        $this->supplierB = Supplier::create(['name' => 'Supplier B']);
        $this->customerC = Customer::create(['name' => 'Customer C']);
    }

    public function test_the_full_section_10b_scenario(): void
    {
        [$b1, $b2] = $this->t1_purchase_same_product_twice_with_discount();
        $b3 = $this->t2_second_purchase_paid_in_full();
        $sale = $this->t3_sale_spanning_two_batches($b1, $b2, $b3);
        $returns = $this->t4_three_separate_single_unit_returns($sale, $b1, $b2);
        $this->t5_delete_return_three($returns[2], $b1);
        $this->t6_purchase_return_against_a_fully_paid_purchase($b3);
        $this->t7_stock_adjustment_out($b1);
        $this->t8_oversell_is_blocked();

        $this->finalAssertions($b1, $b2, $b3);
    }

    /** T1 — Purchase, same product twice, with discount. */
    private function t1_purchase_same_product_twice_with_discount(): array
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplierA,
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10_000],
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 12_000],
            ],
            user: $this->user,
            purchaseDate: now(),
            discountAmount: 4_000,
            amountPaid: 0,
        );

        $this->assertSame(54_000, $purchase->total_amount);
        $this->assertSame(50_000, $purchase->grand_total);

        $batches = StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->orderBy('sequence')
            ->get();

        [$b1, $b2] = [$batches[0], $batches[1]];

        // Costs exactly as typed, discount applied to neither.
        $this->assertSame(3, $b1->quantity_in, 'B1 quantity');
        $this->assertSame(10_000, $b1->unit_cost, 'B1 cost is the typed price, not discounted');
        $this->assertSame(2, $b2->quantity_in, 'B2 quantity');
        $this->assertSame(12_000, $b2->unit_cost, 'B2 cost is the typed price, not discounted');

        $this->assertSame(50_000, (int) $this->supplierA->refresh()->balance);

        // Same timestamp — this is what makes FIFO order deterministic.
        $this->assertSame($b1->received_at->toString(), $b2->received_at->toString());
        $this->assertLessThan($b2->sequence, $b1->sequence, 'B1.sequence < B2.sequence');

        // Two purchase movements, +3 and +2.
        $movements = StockMovement::where('reference_type', StockMovement::REF_PURCHASE)
            ->where('reference_id', $purchase->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame([3, 2], $movements->pluck('quantity')->all());

        $this->globalAssertions();

        return [$b1, $b2];
    }

    /** T2 — Second purchase, paid in full. */
    private function t2_second_purchase_paid_in_full(): StockBatch
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplierB,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->user,
            purchaseDate: now(),
            amountPaid: 60_000,
        );

        $b3 = StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->firstOrFail();

        $this->assertSame(4, $b3->quantity_in);
        $this->assertSame(15_000, $b3->unit_cost);
        $this->assertSame(0, (int) $this->supplierB->refresh()->balance);
        $this->assertSame(9, $this->product->refresh()->quantity, 'Stock = 9');

        $this->globalAssertions();

        return $b3;
    }

    /** T3 — Sale spanning two batches. */
    private function t3_sale_spanning_two_batches(StockBatch $b1, StockBatch $b2, StockBatch $b3): Sale
    {
        $sale = app(SaleService::class)->create(
            customer: $this->customerC,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 30_000]],
            user: $this->user,
            saleDate: now(),
            amountPaid: 100_000,
        );

        $this->assertSame(120_000, $sale->total_amount);

        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->orderBy('sequence')
            ->get();

        // FIFO takes 3 x B1 @ 10,000 + 1 x B2 @ 12,000 -> COGS 42,000.
        $this->assertCount(2, $movements, 'Two movement rows, one per batch touched');
        $this->assertSame(-3, $movements[0]->quantity);
        $this->assertSame($b1->id, $movements[0]->stock_batch_id);
        $this->assertSame(-1, $movements[1]->quantity);
        $this->assertSame($b2->id, $movements[1]->stock_batch_id);

        $cogs = $movements->sum(fn (StockMovement $m) => abs($m->quantity) * $m->unit_cost);
        $this->assertSame(42_000, $cogs, 'COGS = 42,000');

        foreach ($movements as $movement) {
            $this->assertNotNull($movement->reference_item_id, 'reference_item_id is set');
        }

        $this->assertBatch($b1, 0, 3);
        $this->assertBatch($b2, 1, 2);
        $this->assertBatch($b3, 4, 4);
        $this->assertSame(5, $this->product->refresh()->quantity, 'Stock = 5');

        $this->assertSame(20_000, (int) $this->customerC->refresh()->balance);

        $this->globalAssertions();

        return $sale;
    }

    /**
     * T4 — THE CRITICAL TEST: three separate single-unit returns.
     *
     * This is where a wrong implementation reveals itself. Re-deriving "reverse
     * order" from scratch each time sends all three units to B2, giving
     * quantity_remaining = 4 on a batch whose quantity_in is 2, while B1 stays
     * empty forever.
     *
     * @return array<int, SaleReturn>
     */
    private function t4_three_separate_single_unit_returns(Sale $sale, StockBatch $b1, StockBatch $b2): array
    {
        $saleItem = $sale->items()->firstOrFail();
        $service = app(SaleReturnService::class);
        $returns = [];

        // Return #1 -> B2, cost reversed 12,000, B2 back to 2/2.
        $returns[] = $service->create(
            sale: $sale,
            lines: [['sale_item_id' => $saleItem->id, 'quantity' => 1]],
            user: $this->user,
            returnDate: now(),
        );

        $this->assertBatch($b2, 2, 2, 'Return #1 goes to B2 — last consumed, first returned');
        $this->assertSame(12_000, $this->lastReturnCost($returns[0]));

        // Return #2 -> B1, because B2 is fully restored. This is the step that
        // catches a re-derived implementation.
        $returns[] = $service->create(
            sale: $sale,
            lines: [['sale_item_id' => $saleItem->id, 'quantity' => 1]],
            user: $this->user,
            returnDate: now(),
        );

        $this->assertBatch($b1, 1, 3, 'Return #2 moves on to B1 — B2 was fully restored');
        $this->assertBatch($b2, 2, 2, 'B2 must not go above its quantity_in');
        $this->assertSame(10_000, $this->lastReturnCost($returns[1]));

        // Return #3 -> B1 again.
        $returns[] = $service->create(
            sale: $sale,
            lines: [['sale_item_id' => $saleItem->id, 'quantity' => 1]],
            user: $this->user,
            returnDate: now(),
        );

        $this->assertBatch($b1, 2, 3, 'Return #3 goes to B1');
        $this->assertSame(10_000, $this->lastReturnCost($returns[2]));

        $this->assertSame(8, $this->product->refresh()->quantity, 'Stock = 8');

        // Every return movement points at the sale movement it undoes.
        $saleMovementIds = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->pluck('id')
            ->all();

        $returnMovements = StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)->get();

        $this->assertCount(3, $returnMovements);

        foreach ($returnMovements as $movement) {
            $this->assertContains(
                $movement->reverses_movement_id,
                $saleMovementIds,
                'Every return movement reverses a specific sale movement'
            );
        }

        // Balance effects: the customer owed 20,000 and each refund is 30,000.
        $this->assertSame(0, (int) $this->customerC->refresh()->balance, 'Balance floors at 0');

        $cashOut = Payment::where('direction', Payment::DIRECTION_OUT)
            ->where('payable_type', 'sale_return')
            ->orderBy('id')
            ->pluck('amount')
            ->all();

        $this->assertSame([10_000, 30_000, 30_000], array_map('intval', $cashOut));

        $this->assertSame(Sale::STATUS_PARTLY_RETURNED, $sale->refresh()->status);

        $this->globalAssertions();

        return $returns;
    }

    /** T5 — Delete return #3. */
    private function t5_delete_return_three(SaleReturn $return, StockBatch $b1): void
    {
        $cashOutBefore = $this->netCashOut();

        app(SaleReturnService::class)->delete($return, $this->user);

        // B1 matches its T4-step-2 state exactly — this proves
        // reverses_movement_id reverses cleanly.
        $this->assertBatch($b1, 1, 3, 'B1 back to its state after return #2');
        $this->assertSame(7, $this->product->refresh()->quantity, 'Stock = 7');

        $this->assertSame(
            0,
            StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
                ->where('reference_id', $return->id)
                ->count(),
            'Its movement row is gone'
        );

        // 30,000 cash back in: the outbound payment is reversed, so the till
        // nets 30,000 higher than before the delete.
        $this->assertSame(30_000, $cashOutBefore - $this->netCashOut(), '30,000 cash back in');

        $this->assertSame(Sale::STATUS_PARTLY_RETURNED, $return->sale->refresh()->status);

        $this->globalAssertions();
    }

    /** T6 — Purchase return against a fully-paid purchase. */
    private function t6_purchase_return_against_a_fully_paid_purchase(StockBatch $b3): void
    {
        $purchase = Purchase::whereKey($b3->source_id)->firstOrFail();
        $purchaseItem = $purchase->items()->firstOrFail();

        app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchaseItem->id, 'quantity' => 2]],
            user: $this->user,
            returnDate: now(),
        );

        // Its own batch — NOT the oldest.
        $this->assertBatch($b3, 2, 4, 'B3 is deducted, not the oldest batch');

        $this->assertSame(0, (int) $this->supplierB->refresh()->balance, 'Supplier B stays 0, never negative');

        $cashIn = Payment::where('direction', Payment::DIRECTION_IN)
            ->where('payable_type', 'purchase_return')
            ->sum('amount');

        $this->assertSame(30_000, (int) $cashIn, '30,000 recorded as cash in from the supplier');

        $this->assertSame(5, $this->product->refresh()->quantity, 'Stock = 5');

        $this->globalAssertions();
    }

    /** T7 — Stock adjustment out (damage). */
    private function t7_stock_adjustment_out(StockBatch $b1): void
    {
        $customerBalance = (int) $this->customerC->refresh()->balance;
        $supplierABalance = (int) $this->supplierA->refresh()->balance;
        $supplierBBalance = (int) $this->supplierB->refresh()->balance;

        $adjustment = app(StockAdjustmentService::class)->create(
            product: $this->product,
            direction: StockAdjustment::DIRECTION_OUT,
            quantity: 1,
            reason: 'damage',
            user: $this->user,
        );

        // FIFO picks B1, the oldest with stock.
        $this->assertBatch($b1, 0, 3, 'FIFO picks B1, the oldest with stock');

        $movement = StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->where('reference_id', $adjustment->id)
            ->firstOrFail();

        $this->assertSame(10_000, $movement->unit_cost, 'Written off at the true FIFO cost');
        $this->assertSame(-1, $movement->quantity);

        // No balance changes — stock moves, money does not.
        $this->assertSame($customerBalance, (int) $this->customerC->refresh()->balance);
        $this->assertSame($supplierABalance, (int) $this->supplierA->refresh()->balance);
        $this->assertSame($supplierBBalance, (int) $this->supplierB->refresh()->balance);

        $this->assertSame(4, $this->product->refresh()->quantity, 'Stock = 4');

        $this->globalAssertions();
    }

    /** T8 — Oversell is blocked. */
    private function t8_oversell_is_blocked(): void
    {
        $movementsBefore = StockMovement::count();
        $salesBefore = Sale::withTrashed()->count();
        $counterBefore = \App\Models\DocumentCounter::where('prefix', 'INV')->value('next_number');

        try {
            app(SaleService::class)->create(
                customer: $this->customerC,
                lines: [['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => 30_000]],
                user: $this->user,
                saleDate: now(),
            );

            $this->fail('A sale of 6 units against 4 in stock should have been rejected.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('Not enough stock: 4 available.', $e->getMessage());
        }

        // Nothing written — no movements, no partial sale, no document number
        // consumed.
        $this->assertSame($movementsBefore, StockMovement::count(), 'No movements written');
        $this->assertSame($salesBefore, Sale::withTrashed()->count(), 'No partial sale written');
        $this->assertSame(
            $counterBefore,
            \App\Models\DocumentCounter::where('prefix', 'INV')->value('next_number'),
            'No document number consumed'
        );

        $this->globalAssertions();
    }

    private function finalAssertions(StockBatch $b1, StockBatch $b2, StockBatch $b3): void
    {
        $this->assertBatch($b1, 0, 3);
        $this->assertBatch($b2, 2, 2);
        $this->assertBatch($b3, 2, 4);

        $this->assertSame(4, $this->product->refresh()->quantity, 'Final stock = 4 units');

        $value = StockBatch::get()->sum(fn (StockBatch $b) => $b->quantity_remaining * $b->unit_cost);
        $this->assertSame(54_000, (int) $value, 'Final stock value = 54,000');

        $this->assertSame(0, (int) $this->customerC->refresh()->balance, 'Customer C = 0');
        $this->assertSame(50_000, (int) $this->supplierA->refresh()->balance, 'Supplier A = 50,000');
        $this->assertSame(0, (int) $this->supplierB->refresh()->balance, 'Supplier B = 0');

        // Integrity check: +9 - 4 + 1 + 1 - 2 - 1 = 4
        $this->assertSame(
            4,
            (int) StockMovement::where('product_id', $this->product->id)->sum('quantity'),
            'SUM(stock_movements.quantity) = 4'
        );

        $this->assertProfit();
    }

    /**
     * Section 10b profit table:
     * revenue 60,000 - COGS 20,000 + discounts 4,000 - damage 10,000 = 34,000
     */
    private function assertProfit(): void
    {
        $revenue = (int) Sale::sum('total_amount') - (int) SaleReturn::sum('total_amount');
        $this->assertSame(60_000, $revenue, 'Revenue 120,000 - 60,000 returned');

        $cogs = (int) StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->get()->sum(fn ($m) => abs($m->quantity) * $m->unit_cost);

        $cogsReversed = (int) StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->get()->sum(fn ($m) => $m->quantity * $m->unit_cost);

        $this->assertSame(42_000, $cogs, 'COGS 42,000');
        $this->assertSame(22_000, $cogsReversed, 'COGS reversed 22,000');

        $grossProfit = $revenue - ($cogs - $cogsReversed);
        $this->assertSame(40_000, $grossProfit, 'Gross profit 40,000');

        // Discounts received = purchase discounts - discount shares applied.
        $discounts = (int) Purchase::sum('discount_amount')
            - (int) \App\Models\PurchaseReturnItem::sum('discount_share');
        $this->assertSame(4_000, $discounts, 'Discounts received 4,000');

        $writeOff = (int) StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->where('quantity', '<', 0)
            ->get()->sum(fn ($m) => abs($m->quantity) * $m->unit_cost);
        $this->assertSame(10_000, $writeOff, 'Damage write-off 10,000');

        $this->assertSame(34_000, $grossProfit + $discounts - $writeOff, 'Net 34,000');
    }

    /** Section 10b: the six assertions to run globally, after every test. */
    private function globalAssertions(): void
    {
        // 1. No batch has quantity_remaining > quantity_in, and none is negative.
        foreach (StockBatch::all() as $batch) {
            $this->assertLessThanOrEqual(
                $batch->quantity_in,
                $batch->quantity_remaining,
                "Batch {$batch->id} exceeds its quantity_in"
            );
            $this->assertGreaterThanOrEqual(0, $batch->quantity_remaining);
        }

        // 2. products.quantity == SUM(quantity_remaining) == SUM(movements.quantity)
        foreach (Product::all() as $product) {
            $batchSum = (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');
            $movementSum = (int) StockMovement::where('product_id', $product->id)->sum('quantity');

            $this->assertSame($batchSum, $product->quantity, 'Cached quantity matches the batch sum');
            $this->assertSame($movementSum, $product->quantity, 'Cached quantity matches the movement sum');
        }

        // 3. No customer or supplier balance is negative. (The columns are
        //    unsigned, so this also proves nothing tried to write one.)
        $this->assertSame(0, Customer::where('balance', '<', 0)->count());
        $this->assertSame(0, Supplier::where('balance', '<', 0)->count());

        // 4. Every balance equals the latest account_transactions.balance_after.
        foreach ([['customer', Customer::class], ['supplier', Supplier::class]] as [$type, $class]) {
            foreach ($class::all() as $account) {
                $latest = \App\Models\AccountTransaction::where('accountable_type', $type)
                    ->where('accountable_id', $account->id)
                    ->orderByDesc('id')
                    ->first();

                $this->assertSame(
                    (int) ($latest->balance_after ?? 0),
                    (int) $account->balance,
                    "{$type} {$account->id} balance disagrees with its ledger"
                );
            }
        }

        // 5. Every movement for a sale or return has reference_item_id set.
        $this->assertSame(
            0,
            StockMovement::whereIn('reference_type', [
                StockMovement::REF_SALE,
                StockMovement::REF_SALE_RETURN,
                StockMovement::REF_PURCHASE_RETURN,
            ])->whereNull('reference_item_id')->count(),
            'Sale and return movements always carry reference_item_id'
        );

        // 6. No document_no appears twice.
        foreach ([Sale::class, Purchase::class, SaleReturn::class, \App\Models\PurchaseReturn::class,
            Payment::class, StockAdjustment::class, \App\Models\Expense::class] as $class) {
            $numbers = $class::withTrashed()->pluck('document_no');
            $this->assertSame(
                $numbers->count(),
                $numbers->unique()->count(),
                class_basename($class).' has a duplicate document_no'
            );
        }
    }

    private function assertBatch(StockBatch $batch, int $remaining, int $in, string $message = ''): void
    {
        $batch->refresh();

        $this->assertSame($remaining, $batch->quantity_remaining, $message ?: "Batch {$batch->id} remaining");
        $this->assertSame($in, $batch->quantity_in, $message ?: "Batch {$batch->id} quantity_in");
    }

    private function lastReturnCost(SaleReturn $return): int
    {
        return (int) StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->where('reference_id', $return->id)
            ->get()
            ->sum(fn (StockMovement $m) => $m->quantity * $m->unit_cost);
    }

    private function netCashOut(): int
    {
        return (int) Payment::where('direction', Payment::DIRECTION_OUT)->sum('amount')
            - (int) Payment::where('direction', Payment::DIRECTION_IN)->sum('amount');
    }
}
