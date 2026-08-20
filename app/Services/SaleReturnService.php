<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 7: partial line, whole line, or whole sale — one form, one mechanism.
 *
 * Returns are never blocked by the edit lock. A return creates a new forward
 * document; it doesn't rewrite history.
 */
class SaleReturnService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private FifoService $fifo,
        private LedgerService $ledger,
        private PaymentService $payments,
    ) {}

    /**
     * @param  array<int, array{sale_item_id: int, quantity: int}>  $lines
     */
    public function create(
        Sale $sale,
        array $lines,
        User $user,
        Carbon $returnDate,
        ?string $reason = null,
        string $paymentMethod = 'cash',
    ): SaleReturn {
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new RuntimeException(__('Nothing to return — every line is zero.'));
        }

        if (books_closed_on($returnDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use ($sale, $lines, $user, $returnDate, $reason, $paymentMethod) {
            $return = SaleReturn::create([
                'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_SALE_RETURN),
                'sale_id' => $sale->id,
                // Copied from the sale for fast reporting; the sale stays the
                // source of truth and the two must never diverge.
                'customer_id' => $sale->customer_id,
                'user_id' => $user->id,
                'total_amount' => 0,
                'return_date' => $returnDate,
                'reason' => $reason,
            ]);

            $occurredAt = $returnDate->copy()->setTimeFrom(now());
            $total = 0;

            // Lock the sale lines in a consistent order, same reasoning as sales.
            $saleItems = SaleItem::whereIn('id', array_column($lines, 'sale_item_id'))
                ->where('sale_id', $sale->id)
                ->orderBy('product_id')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $ordered = collect($lines)->sortBy(
                fn (array $l) => [$saleItems[$l['sale_item_id']]->product_id ?? 0, $l['sale_item_id']]
            );

            foreach ($ordered as $line) {
                $saleItem = $saleItems[$line['sale_item_id']]
                    ?? throw new RuntimeException(__('That line does not belong to this sale.'));

                $quantity = (int) $line['quantity'];

                // Section 7: never validate a sale return against stock on hand.
                // A returning customer is ADDING stock; the only limit is what
                // they bought and haven't already returned.
                if ($quantity > $saleItem->returnableQuantity()) {
                    throw new RuntimeException(__('Only :count left to return on that line.', [
                        'count' => $saleItem->returnableQuantity(),
                    ]));
                }

                $returnItem = SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $quantity,
                    // Section 7: the refund uses THIS line's unit price — the same
                    // product on two lines refunds differently.
                    'unit_price' => $saleItem->unit_price,
                ]);

                // The exact algorithm: units go back to the batches they actually
                // came from, in reverse order of consumption, tracked at the
                // movement level so repeated partial returns stay exact.
                $this->fifo->restoreForSaleItem(
                    saleItem: $saleItem,
                    quantity: $quantity,
                    saleReturnId: $return->id,
                    saleReturnItemId: $returnItem->id,
                    occurredAt: $occurredAt,
                    user: $user,
                );

                // Cumulative, per line.
                $saleItem->forceFill([
                    'quantity_returned' => $saleItem->quantity_returned + $quantity,
                ])->save();

                $total += $quantity * $saleItem->unit_price;
            }

            $return->forceFill(['total_amount' => $total])->save();

            $this->settleRefund($return, $sale, $total, $user, $paymentMethod, $occurredAt);

            // Section 4: recompute inside the same transaction as the return.
            $sale->refresh()->recalculateStatus();

            return $return->refresh();
        });
    }

    /**
     * Section 7: a refund first clears what the customer owes; anything left over
     * is paid back in cash. The balance never goes below zero, and the cash
     * portion is recorded as money leaving the till — never as a negative number.
     */
    private function settleRefund(
        SaleReturn $return,
        Sale $sale,
        int $total,
        User $user,
        string $paymentMethod,
        Carbon $occurredAt,
    ): void {
        $customer = $sale->customer()->firstOrFail();

        $result = $this->ledger->post(
            account: $customer,
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
                direction: Payment::DIRECTION_OUT,
                user: $user,
                method: $paymentMethod,
                paidAt: $occurredAt,
                notes: __('Cash refund for :document', ['document' => $return->document_no]),
            );
        }
    }

    /**
     * Section 5: deleting a return is trivial and safe — take its movements,
     * subtract each from its batch, delete them. The reverses_movement_id links
     * restore the earlier state exactly, with no recomputation.
     *
     * Section 8b: a soft delete must still reverse the document's effects. It is
     * a reversal plus a hidden record, not a way to skip the reversal.
     */
    public function delete(SaleReturn $return, User $user): void
    {
        if (books_closed_on($return->return_date)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        DB::transaction(function () use ($return, $user) {
            $movements = StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
                ->where('reference_id', $return->id)
                ->lockForUpdate()
                ->get();

            $this->fifo->reverseMovements($movements);

            foreach ($return->items as $item) {
                $saleItem = SaleItem::whereKey($item->sale_item_id)->lockForUpdate()->firstOrFail();

                $saleItem->forceFill([
                    'quantity_returned' => $saleItem->quantity_returned - $item->quantity,
                ])->save();
            }

            // Put back what the refund took off the customer's balance, and undo
            // the cash that went out of the till.
            $customer = $return->customer()->firstOrFail();

            $applied = (int) AccountTransaction::where('accountable_type', 'customer')
                ->where('accountable_id', $customer->id)
                ->where('reference_type', 'sale_return')
                ->where('reference_id', $return->id)
                ->sum('amount');

            if ($applied !== 0) {
                $this->ledger->post(
                    account: $customer,
                    type: AccountTransaction::TYPE_RETURN,
                    amount: -$applied,
                    reference: $return,
                    user: $user,
                    notes: __('Reversal of :document', ['document' => $return->document_no]),
                );
            }

            // The cash that went out comes back in. Soft-deleting the outbound
            // payment is the reversal — the till nets to where it was.
            $return->payments()->get()->each->delete();

            $return->delete();

            $return->sale->refresh()->recalculateStatus();
        });
    }
}
