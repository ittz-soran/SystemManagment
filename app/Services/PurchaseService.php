<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 5, build step 4: purchases and the batches they create — the foundation.
 */
class PurchaseService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private LedgerService $ledger,
        private PaymentService $payments,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int,
     *          entered_currency?: string, entered_amount?: int|null}>  $lines
     */
    public function create(
        Supplier $supplier,
        array $lines,
        User $user,
        Carbon $purchaseDate,
        int $discountAmount = 0,
        int $amountPaid = 0,
        ?string $supplierInvoiceNo = null,
        ?int $exchangeRate = null,
        string $paymentMethod = 'cash',
    ): Purchase {
        if ($lines === []) {
            throw new RuntimeException(__('A purchase needs at least one line.'));
        }

        if (books_closed_on($purchaseDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use (
            $supplier, $lines, $user, $purchaseDate, $discountAmount,
            $amountPaid, $supplierInvoiceNo, $exchangeRate, $paymentMethod
        ) {
            $totalAmount = array_sum(array_map(
                fn (array $l) => $l['quantity'] * $l['unit_price'],
                $lines
            ));

            // Section 6: grand_total = total - discount. The discount is SIGNED,
            // because a supplier may round up. It never touches item prices or
            // batch costs.
            $grandTotal = $totalAmount - $discountAmount;

            if ($grandTotal < 0) {
                throw new RuntimeException(__('The discount cannot exceed the purchase total.'));
            }

            if ($amountPaid > $grandTotal) {
                throw new RuntimeException(__('Paid amount cannot exceed the grand total.'));
            }

            $purchase = Purchase::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_PURCHASE),
                'supplier_id' => $supplier->id,
                'user_id' => $user->id,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'status' => Purchase::STATUS_ACTIVE,
                'exchange_rate' => $exchangeRate,
                'purchase_date' => $purchaseDate,
            ]);

            // The batch timestamp. All lines of one purchase share it, which is
            // exactly why stock_batches.sequence exists.
            $receivedAt = $purchaseDate->copy()->setTimeFrom(now());

            foreach (array_values($lines) as $index => $line) {
                $product = Product::whereKey($line['product_id'])->firstOrFail();

                $item = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'quantity_returned' => 0,
                    'entered_currency' => $line['entered_currency'] ?? 'IQD',
                    'entered_amount' => $line['entered_amount'] ?? null,
                    'sequence' => $index + 1,
                ]);

                // Section 4: the same product on two lines at two prices is
                // supported and NEVER merged. Each line becomes its own batch,
                // which is how FIFO keeps two costs for one product straight.
                $this->fifo->createBatch(
                    product: $product,
                    sourceType: StockBatch::SOURCE_PURCHASE,
                    sourceId: $purchase->id,
                    unitCost: $item->unit_price,
                    quantity: $item->quantity,
                    receivedAt: $receivedAt,
                    sequence: $item->sequence,
                    user: $user,
                    purchaseItemId: $item->id,
                );

                // Section 9: the cart's default purchase price for next time.
                $product->forceFill(['purchase_price' => $item->unit_price])->save();
            }

            // The shop now owes the supplier the grand total.
            $this->ledger->post(
                account: $supplier,
                type: AccountTransaction::TYPE_PURCHASE,
                amount: $grandTotal,
                reference: $purchase,
                user: $user,
                notes: $purchase->document_no,
            );

            if ($amountPaid > 0) {
                $this->payments->record(
                    payable: $purchase,
                    amount: $amountPaid,
                    direction: Payment::DIRECTION_IN,
                    user: $user,
                    method: $paymentMethod,
                    paidAt: $receivedAt,
                );

                // Paying the supplier reduces what the shop owes.
                $this->ledger->post(
                    account: $supplier,
                    type: AccountTransaction::TYPE_PAYMENT,
                    amount: -$amountPaid,
                    reference: $purchase,
                    user: $user,
                    notes: $purchase->document_no,
                );
            }

            return $purchase->refresh();
        });
    }

    /**
     * Section 4: derived from the lines, never typed. Driven by purchase returns.
     */
    public function recalculateStatus(Purchase $purchase): string
    {
        $bought = (int) $purchase->items()->sum('quantity');
        $returned = (int) $purchase->items()->sum('quantity_returned');

        $status = match (true) {
            $returned <= 0 => Purchase::STATUS_ACTIVE,
            $returned >= $bought => Purchase::STATUS_RETURNED,
            default => Purchase::STATUS_PARTLY_RETURNED,
        };

        $purchase->forceFill(['status' => $status])->save();

        return $status;
    }
}
