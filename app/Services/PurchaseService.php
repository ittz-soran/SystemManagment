<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
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

            $this->applyLines($purchase, $lines, $user, $purchaseDate);

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
                    // Section 4: "direction = out is money leaving the till — a
                    // cash refund to a customer, or paying a supplier."
                    direction: Payment::DIRECTION_OUT,
                    user: $user,
                    method: $paymentMethod,
                    paidAt: $purchaseDate->copy()->setTimeFrom(now()),
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
     * Section 8: one transaction — reverse, then re-apply.
     *
     * "No chronological replay is needed — the lock conditions make it
     * impossible for an edit to affect any other document." That is what makes
     * this safe: rule 2 guarantees every batch is still untouched, so removing
     * them can strand nothing.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int,
     *          entered_currency?: string, entered_amount?: int|null}>  $lines
     */
    public function update(
        Purchase $purchase,
        Supplier $supplier,
        array $lines,
        User $user,
        Carbon $purchaseDate,
        int $discountAmount = 0,
        ?string $supplierInvoiceNo = null,
        ?int $exchangeRate = null,
    ): Purchase {
        if ($lines === []) {
            throw new RuntimeException(__('A purchase needs at least one line.'));
        }

        if (books_closed_on($purchaseDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use (
            $purchase, $supplier, $lines, $user, $purchaseDate,
            $discountAmount, $supplierInvoiceNo, $exchangeRate
        ) {
            // Section 8: "Controllers call it AND re-check inside the
            // transaction." Between the page loading and this running, someone
            // else may have sold from these batches.
            $lock = $purchase->fresh()->canBeModified($user);

            if (! $lock['allowed']) {
                throw new RuntimeException($lock['reason']);
            }

            $totalAmount = array_sum(array_map(
                fn (array $l) => $l['quantity'] * $l['unit_price'],
                $lines
            ));

            $grandTotal = $totalAmount - $discountAmount;

            if ($grandTotal < 0) {
                throw new RuntimeException(__('The discount cannot exceed the purchase total.'));
            }

            // Section 8 rule 4: the new grand total must still cover what has
            // already been paid, or the supplier would be owed a negative sum.
            $alreadyPaid = $purchase->amountPaid();

            if ($grandTotal < $alreadyPaid) {
                throw new RuntimeException(__(
                    'The new total of :total is less than the :paid already paid. Record a refund first.',
                    ['total' => money($grandTotal), 'paid' => money($alreadyPaid)],
                ));
            }

            $before = $this->snapshot($purchase);

            $this->reverseStock($purchase);

            // Payments survive the edit — the money really did change hands —
            // so only the document's own ledger effect is undone and re-posted.
            $this->ledger->reverseDocument(
                account: $supplier,
                reference: $purchase,
                user: $user,
                notes: __('Edit of :document', ['document' => $purchase->document_no]),
            );

            $purchase->update([
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'exchange_rate' => $exchangeRate,
                'purchase_date' => $purchaseDate,
            ]);

            $this->applyLines($purchase, $lines, $user, $purchaseDate);

            // What is still owed after the edit: the new total less what was
            // already paid, posted as one figure so the ledger reads cleanly.
            $this->ledger->post(
                account: $supplier,
                type: AccountTransaction::TYPE_PURCHASE,
                amount: $grandTotal - $alreadyPaid,
                reference: $purchase,
                user: $user,
                notes: $purchase->document_no,
            );

            app(ActivityLogger::class)->logModel(
                'update',
                $purchase,
                __('Edited purchase :document', ['document' => $purchase->document_no]),
                $before,
            );

            return $purchase->refresh();
        });
    }

    /**
     * Section 8b: a soft delete "must still reverse its effects — restore
     * batches, reverse ledger rows — exactly like an edit. It is a reversal
     * plus a hidden record, not a way to skip the reversal."
     */
    public function delete(Purchase $purchase, User $user): void
    {
        if (books_closed_on($purchase->purchase_date)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        DB::transaction(function () use ($purchase, $user) {
            $state = $purchase->fresh()->canBeDeleted($user);

            if (! $state['allowed']) {
                throw new RuntimeException($state['reason']);
            }

            $before = $this->snapshot($purchase);
            $supplier = $purchase->supplier()->firstOrFail();

            $this->reverseStock($purchase);

            $this->ledger->reverseDocument(
                account: $supplier,
                reference: $purchase,
                user: $user,
            );

            // The money that changed hands is undone with the document.
            $purchase->payments()->get()->each->delete();

            $purchase->delete();

            app(ActivityLogger::class)->logModel(
                'delete',
                $purchase,
                __('Deleted purchase :document', ['document' => $purchase->document_no]),
                $before,
            );
        });
    }

    /**
     * Remove every trace this purchase left in stock.
     *
     * Safe only because the lock rules already proved the batches are untouched
     * — every quantity_remaining still equals its quantity_in, so nothing else
     * has drawn on them and no movement can be orphaned.
     */
    private function reverseStock(Purchase $purchase): void
    {
        $batchIds = $purchase->batches()->pluck('id');

        // stock_batches.purchase_item_id is `restrict` on stock_movements, so
        // the movements go first.
        \App\Models\StockMovement::whereIn('stock_batch_id', $batchIds)->delete();

        StockBatch::whereIn('id', $batchIds)->delete();

        $productIds = $purchase->items()->pluck('product_id')->unique();

        $purchase->items()->delete();

        // Section 4: rewritten as SUM(quantity_remaining) inside this same
        // transaction, never as an incremental adjustment.
        foreach ($productIds as $productId) {
            $this->fifo->syncProductQuantity(Product::findOrFail($productId));
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function applyLines(Purchase $purchase, array $lines, User $user, Carbon $purchaseDate): void
    {
        $receivedAt = $purchaseDate->copy()->setTimeFrom(now());

        foreach (array_values($lines) as $index => $line) {
            $product = Product::whereKey($line['product_id'])->firstOrFail();

            // Nothing is ever bought into stock for a service, so there would be
            // no batch to open and the cost of one is a contradiction. Refused
            // here as well as in the form, because this is where a batch would
            // be written.
            if (! $product->tracksStock()) {
                throw new RuntimeException(__('A service cannot be purchased — it has no stock and no cost.'));
            }

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

            $product->forceFill(['purchase_price' => $item->unit_price])->save();
        }
    }

    /**
     * Section 8: "the full previous version in old_values JSON."
     *
     * @return array<string, mixed>
     */
    private function snapshot(Purchase $purchase): array
    {
        return [
            'document_no' => $purchase->document_no,
            'supplier_id' => $purchase->supplier_id,
            'supplier_invoice_no' => $purchase->supplier_invoice_no,
            'total_amount' => $purchase->total_amount,
            'discount_amount' => $purchase->discount_amount,
            'grand_total' => $purchase->grand_total,
            'exchange_rate' => $purchase->exchange_rate,
            'purchase_date' => $purchase->purchase_date?->toDateString(),
            'lines' => $purchase->items()->get()
                ->map(fn (PurchaseItem $i) => [
                    'product_id' => $i->product_id,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                ])->all(),
        ];
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
