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

            // Section 5 (Concurrency): lock batches in a CONSISTENT order. A sale
            // touching two products must lock them in the same order every time,
            // or two concurrent sales can deadlock. product_id ascending is that
            // order; FifoService then locks within a product by received_at,
            // sequence.
            usort($items, fn (SaleItem $a, SaleItem $b) => $a->product_id <=> $b->product_id
                ?: $a->sequence <=> $b->sequence);

            foreach ($items as $item) {
                // Consuming writes one movement per batch touched, each carrying
                // reference_item_id — without which per-line returns are impossible.
                $this->fifo->consume(
                    product: Product::whereKey($item->product_id)->firstOrFail(),
                    quantity: $item->quantity,
                    referenceType: StockMovement::REF_SALE,
                    referenceId: $sale->id,
                    referenceItemId: $item->id,
                    occurredAt: $occurredAt,
                    user: $user,
                );
            }

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
    public function nextBatchCost(Product $product): ?int
    {
        $batch = $product->stockBatches()->withStock()->fifoOrder()->first();

        return $batch?->unit_cost;
    }
}
