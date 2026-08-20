<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 5: purchase returns are simpler than sale returns — one purchase_item
 * maps to exactly one batch, so there is no ordering question. Deduct from that
 * batch, not the oldest: you're returning those specific goods to that supplier.
 */
class PurchaseReturnService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private LedgerService $ledger,
        private PaymentService $payments,
        private PurchaseService $purchases,
    ) {}

    /**
     * @param  array<int, array{purchase_item_id: int, quantity: int, unit_price?: int,
     *          discount_share?: int}>  $lines
     */
    public function create(
        Purchase $purchase,
        array $lines,
        User $user,
        Carbon $returnDate,
        ?string $reason = null,
        string $paymentMethod = 'cash',
    ): PurchaseReturn {
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new RuntimeException(__('Nothing to return — every line is zero.'));
        }

        if (books_closed_on($returnDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use ($purchase, $lines, $user, $returnDate, $reason, $paymentMethod) {
            $return = PurchaseReturn::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_PURCHASE_RETURN),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => $user->id,
                'total_amount' => 0,
                'return_date' => $returnDate,
                'reason' => $reason,
            ]);

            $occurredAt = $returnDate->copy()->setTimeFrom(now());
            $total = 0;

            $purchaseItems = PurchaseItem::whereIn('id', array_column($lines, 'purchase_item_id'))
                ->where('purchase_id', $purchase->id)
                ->orderBy('product_id')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $ordered = collect($lines)->sortBy(
                fn (array $l) => [$purchaseItems[$l['purchase_item_id']]->product_id ?? 0, $l['purchase_item_id']]
            );

            foreach ($ordered as $line) {
                $purchaseItem = $purchaseItems[$line['purchase_item_id']]
                    ?? throw new RuntimeException(__('That line does not belong to this purchase.'));

                $quantity = (int) $line['quantity'];

                if ($quantity > $purchaseItem->returnableQuantity()) {
                    throw new RuntimeException(__('Only :count left to return on that line.', [
                        'count' => $purchaseItem->returnableQuantity(),
                    ]));
                }

                // Section 7: the credit is pre-filled at the full typed unit price.
                // The discount share defaults to 0 — Soran decides per return
                // whether the supplier credits proportionally.
                $unitPrice = (int) ($line['unit_price'] ?? $purchaseItem->unit_price);
                $discountShare = (int) ($line['discount_share'] ?? 0);

                $returnItem = PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id' => $purchaseItem->product_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_share' => $discountShare,
                ]);

                $batch = StockBatch::where('purchase_item_id', $purchaseItem->id)->firstOrFail();

                $this->fifo->deductFromBatch(
                    batch: $batch,
                    quantity: $quantity,
                    purchaseReturnId: $return->id,
                    purchaseReturnItemId: $returnItem->id,
                    occurredAt: $occurredAt,
                    user: $user,
                );

                $purchaseItem->forceFill([
                    'quantity_returned' => $purchaseItem->quantity_returned + $quantity,
                ])->save();

                $total += $returnItem->creditTotal();
            }

            $return->forceFill(['total_amount' => $total])->save();

            $this->settleCredit($return, $purchase, $total, $user, $paymentMethod, $occurredAt);

            $this->purchases->recalculateStatus($purchase->refresh());

            return $return->refresh();
        });
    }

    /**
     * Section 4: if a return exceeds what you still owe — the purchase was already
     * paid — the balance clears to zero and the remainder is cash received back
     * from the supplier.
     */
    private function settleCredit(
        PurchaseReturn $return,
        Purchase $purchase,
        int $total,
        User $user,
        string $paymentMethod,
        Carbon $occurredAt,
    ): void {
        $supplier = $purchase->supplier()->firstOrFail();

        $result = $this->ledger->post(
            account: $supplier,
            type: AccountTransaction::TYPE_RETURN,
            amount: -$total,
            reference: $return,
            user: $user,
            notes: $return->document_no,
        );

        $cashBack = $result['unapplied'];

        if ($cashBack > 0) {
            $this->payments->record(
                payable: $return,
                amount: $cashBack,
                direction: Payment::DIRECTION_IN,
                user: $user,
                method: $paymentMethod,
                paidAt: $occurredAt,
                notes: __('Cash received back for :document', ['document' => $return->document_no]),
            );
        }
    }
}
