<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 5, build step 5: sales, FIFO consumption and stock_movements.
 */
class SaleService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private LedgerService $ledger,
        private PaymentService $payments,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $lines
     */
    public function create(
        Customer $customer,
        array $lines,
        User $user,
        Carbon $saleDate,
        int $amountPaid = 0,
        string $paymentMethod = 'cash',
    ): Sale {
        if ($lines === []) {
            throw new RuntimeException(__('A sale needs at least one line.'));
        }

        if (books_closed_on($saleDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use ($customer, $lines, $user, $saleDate, $amountPaid, $paymentMethod) {
            // Section 4: sales have NO discount field. Price is per line.
            $totalAmount = array_sum(array_map(
                fn (array $l) => $l['quantity'] * $l['unit_price'],
                $lines
            ));

            // Section 4: the Cash Customer must always be paid in full (no loan).
            if ($customer->is_system && $amountPaid < $totalAmount) {
                throw new RuntimeException(
                    __('The Cash Customer must be paid in full. Choose a named customer to sell on loan.')
                );
            }

            if ($amountPaid > $totalAmount) {
                throw new RuntimeException(__('Paid amount cannot exceed the sale total.'));
            }

            $sale = Sale::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_SALE),
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'total_amount' => $totalAmount,
                'status' => Sale::STATUS_ACTIVE,
                'sale_date' => $saleDate,
            ]);

            $occurredAt = $saleDate->copy()->setTimeFrom(now());

            // Writes one movement per batch touched, each carrying
            // reference_item_id — without which per-line returns are impossible.
            $this->applyLines($sale, $lines, $user, $saleDate);

            // The customer owes the whole sale until they pay.
            $this->ledger->post(
                account: $customer,
                type: AccountTransaction::TYPE_SALE,
                amount: $totalAmount,
                reference: $sale,
                user: $user,
                notes: $sale->document_no,
            );

            if ($amountPaid > 0) {
                $this->payments->record(
                    payable: $sale,
                    amount: $amountPaid,
                    direction: Payment::DIRECTION_IN,
                    user: $user,
                    method: $paymentMethod,
                    paidAt: $occurredAt,
                );

                $this->ledger->post(
                    account: $customer,
                    type: AccountTransaction::TYPE_PAYMENT,
                    amount: -$amountPaid,
                    reference: $sale,
                    user: $user,
                    notes: $sale->document_no,
                );
            }

            return $sale->refresh();
        });
    }

    /**
     * Section 9b: the below-cost warning. Returns the FIFO cost of the batch that
     * WOULD be consumed next, so the cart can warn without blocking — Soran may
     * sell below cost deliberately for clearance or damaged goods.
     */
    /**
     * Section 8: one transaction — reverse, then re-apply.
     *
     * Lock rule 3 is what makes this safe. If a later sale had taken the rest
     * of a batch, putting these units back and re-running FIFO would hand this
     * sale the cheaper stock the later one is already holding — "FIFO order
     * inverts and both are wrong". With no later movement, the batches are
     * exactly as this sale left them.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $lines
     */
    public function update(
        Sale $sale,
        Customer $customer,
        array $lines,
        User $user,
        Carbon $saleDate,
    ): Sale {
        if ($lines === []) {
            throw new RuntimeException(__('A sale needs at least one line.'));
        }

        if (books_closed_on($saleDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use ($sale, $customer, $lines, $user, $saleDate) {
            // Section 8: re-checked inside the transaction, not just before it.
            $lock = $sale->fresh()->canBeModified($user);

            if (! $lock['allowed']) {
                throw new RuntimeException($lock['reason']);
            }

            $totalAmount = array_sum(array_map(
                fn (array $l) => $l['quantity'] * $l['unit_price'],
                $lines
            ));

            $alreadyPaid = $sale->amountPaid();

            // Section 8 rule 4.
            if ($totalAmount < $alreadyPaid) {
                throw new RuntimeException(__(
                    'The new total of :total is less than the :paid already paid. Record a refund first.',
                    ['total' => money($totalAmount), 'paid' => money($alreadyPaid)],
                ));
            }

            if ($customer->is_system && $alreadyPaid < $totalAmount) {
                throw new RuntimeException(
                    __('The Cash Customer must be paid in full. Choose a named customer to sell on loan.')
                );
            }

            $before = $this->snapshot($sale);

            $this->reverseStock($sale);

            $this->ledger->reverseDocument(
                account: $sale->customer()->firstOrFail(),
                reference: $sale,
                user: $user,
                notes: __('Edit of :document', ['document' => $sale->document_no]),
            );

            $sale->update([
                'customer_id' => $customer->id,
                'total_amount' => $totalAmount,
                'sale_date' => $saleDate,
            ]);

            $this->applyLines($sale, $lines, $user, $saleDate);

            // What the customer still owes after the edit.
            $this->ledger->post(
                account: $customer,
                type: AccountTransaction::TYPE_SALE,
                amount: $totalAmount - $alreadyPaid,
                reference: $sale,
                user: $user,
                notes: $sale->document_no,
            );

            app(ActivityLogger::class)->logModel(
                'update',
                $sale,
                __('Edited sale :document', ['document' => $sale->document_no]),
                $before,
            );

            return $sale->refresh();
        });
    }

    /**
     * Section 8b: the soft delete reverses the sale's effects first — it is
     * "a reversal plus a hidden record, not a way to skip the reversal".
     */
    public function delete(Sale $sale, User $user): void
    {
        if (books_closed_on($sale->sale_date)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        DB::transaction(function () use ($sale, $user) {
            $lock = $sale->fresh()->canBeModified($user);

            if (! $lock['allowed']) {
                throw new RuntimeException($lock['reason']);
            }

            $before = $this->snapshot($sale);
            $customer = $sale->customer()->firstOrFail();

            $this->reverseStock($sale);

            $this->ledger->reverseDocument(
                account: $customer,
                reference: $sale,
                user: $user,
            );

            $sale->payments()->get()->each->delete();

            $sale->delete();

            app(ActivityLogger::class)->logModel(
                'delete',
                $sale,
                __('Deleted sale :document', ['document' => $sale->document_no]),
                $before,
            );
        });
    }

    /**
     * Put every unit this sale took back into the batch it came from.
     *
     * The movements record which batch each unit came from, so this restores
     * the exact cost layers rather than guessing — the same mechanism that
     * makes deleting a return exact.
     */
    private function reverseStock(Sale $sale): void
    {
        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->lockForUpdate()
            ->get();

        $this->fifo->reverseMovements($movements);

        $sale->items()->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function applyLines(Sale $sale, array $lines, User $user, Carbon $saleDate): void
    {
        $occurredAt = $saleDate->copy()->setTimeFrom(now());

        $items = [];

        foreach (array_values($lines) as $index => $line) {
            $items[] = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'quantity_returned' => 0,
                'sequence' => $index + 1,
            ]);
        }

        // Section 5 (Concurrency): lock batches in a consistent order, so two
        // concurrent saves touching the same two products cannot deadlock.
        usort($items, fn (SaleItem $a, SaleItem $b) => $a->product_id <=> $b->product_id
            ?: $a->sequence <=> $b->sequence);

        foreach ($items as $item) {
            $product = Product::whereKey($item->product_id)->firstOrFail();

            // A service is sold, not stocked: nothing was ever bought, so there
            // is no batch to draw down and no cost to record. Its whole price is
            // profit, which is what the absence of a movement means to the
            // report — revenue with no COGS against it.
            if (! $product->tracksStock()) {
                continue;
            }

            $this->fifo->consume(
                product: $product,
                quantity: $item->quantity,
                referenceType: StockMovement::REF_SALE,
                referenceId: $sale->id,
                referenceItemId: $item->id,
                occurredAt: $occurredAt,
                user: $user,
            );
        }
    }

    /**
     * Section 8: "the full previous version in old_values JSON."
     *
     * @return array<string, mixed>
     */
    private function snapshot(Sale $sale): array
    {
        return [
            'document_no' => $sale->document_no,
            'customer_id' => $sale->customer_id,
            'total_amount' => $sale->total_amount,
            'sale_date' => $sale->sale_date?->toDateString(),
            'lines' => $sale->items()->get()
                ->map(fn (SaleItem $i) => [
                    'product_id' => $i->product_id,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                ])->all(),
        ];
    }

    public function nextBatchCost(Product $product): ?int
    {
        $batch = $product->stockBatches()->withStock()->fifoOrder()->first();

        return $batch?->unit_cost;
    }
}
