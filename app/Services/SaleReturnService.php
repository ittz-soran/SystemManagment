<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        private PurchaseReturnService $purchaseReturns,
    ) {}

    /**
     * Which purchase each returned unit came from — Soran, 2026-09-23.
     *
     * *"supllier get me cost of it"*. A faulty unit goes back to the supplier
     * it was bought from, and the shop cannot be expected to remember which
     * that was: the stock movements already know, because every unit sold
     * carries the batch it left, and every purchased batch carries its
     * purchase item.
     *
     * ⚠️ **THE ORDER MATTERS AND IT IS NOT FIFO.** The units are taken in the
     * same order `restoreForSaleItem()` puts them back — `sequence` DESCENDING,
     * last consumed first returned. A line filled from two purchases must send
     * back the units the return actually restored; walking them oldest-first
     * would refund the wrong supplier at the wrong cost while the stock went
     * back to a different batch.
     *
     * ⚠️ A batch with no `purchase_item_id` has no supplier to go back to —
     * opening stock entered by adjustment, or anything carried in from another
     * room. Reported as such rather than skipped silently, so the screen can
     * say why it is offering nothing.
     *
     * @return Collection<int, object> one row per batch drawn on, in return order
     */
    public function originsFor(SaleItem $saleItem, int $quantity): Collection
    {
        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_item_id', $saleItem->id)
            ->outbound()
            ->orderByDesc('sequence')
            ->with('batch.purchaseItem.purchase.supplier')
            ->get();

        $remaining = $quantity;
        $origins = collect();

        foreach ($movements as $movement) {
            if ($remaining < 1) {
                break;
            }

            // Stored negative on the way out; what is available to send back is
            // however many of them this return is taking.
            $take = min($remaining, abs((int) $movement->quantity));
            $remaining -= $take;

            $purchaseItem = $movement->batch?->purchaseItem;

            $origins->push((object) [
                'quantity' => $take,
                'unit_cost' => (int) $movement->unit_cost,
                'purchase_item' => $purchaseItem,
                'purchase' => $purchaseItem?->purchase,
                'supplier' => $purchaseItem?->purchase?->supplier,
            ]);
        }

        return $origins;
    }

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
        array $faultyLines = [],
    ): SaleReturn {
        $lines = array_values(array_filter($lines, fn (array $l) => ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new RuntimeException(__('Nothing to return — every line is zero.'));
        }

        if (books_closed_on($returnDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use ($sale, $lines, $user, $returnDate, $reason, $paymentMethod, $faultyLines) {
            /*
             * The same claim a sale makes, in the same order, before anything is
             * written — FifoService::claim(). This is the other half of the pair
             * that deadlocked: a sale and a return take DIFFERENT document
             * counter rows, so nothing serialises them the way two sales are
             * serialised, and they met on the same shelf facing opposite ways.
             */
            $this->fifo->claim(
                $sale->items()->whereIn('id', array_column($lines, 'sale_item_id'))->pluck('product_id')
            );

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
            $saleItems = SaleItem::with('product')
                ->whereIn('id', array_column($lines, 'sale_item_id'))
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
                //
                // A service took nothing off the shelf, so there is nothing to
                // put back — undoing one is only the money. Asking the engine
                // would fail looking for movements that were never written.
                if ($saleItem->product->tracksStock()) {
                    $this->fifo->restoreForSaleItem(
                        saleItem: $saleItem,
                        quantity: $quantity,
                        saleReturnId: $return->id,
                        saleReturnItemId: $returnItem->id,
                        occurredAt: $occurredAt,
                        user: $user,
                    );
                }

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

            /*
             * ⚠️ **BOTH DOCUMENTS OR NEITHER.** Inside the same transaction as
             * the sale return, so the shop can never end up having refunded a
             * customer with no supplier document to recover the cost on.
             *
             * After the restore, deliberately: the purchase return consumes the
             * very units the sale return has just put back into their batches.
             */
            $this->sendBackToSuppliers($return, $lines, $faultyLines, $user, $returnDate);

            return $return->refresh();
        });
    }

    /**
     * Send the faulty units back to the suppliers they came from.
     *
     * Soran, 2026-09-23: *"supllier get me cost of it"*. One purchase return
     * per purchase drawn on — ⚠️ a single sale line filled from two purchases
     * goes back to two different suppliers, at the two different costs they
     * were each bought at.
     *
     * @param  array<int, array{sale_item_id: int, quantity: int}>  $lines
     * @param  list<int>  $faultyLines  sale_item_ids the shop ticked
     */
    private function sendBackToSuppliers(
        SaleReturn $return,
        array $lines,
        array $faultyLines,
        User $user,
        Carbon $returnDate,
    ): void {
        if ($faultyLines === []) {
            return;
        }

        /** @var array<int, array<int, int>> $byPurchase  purchase id => [purchase item id => quantity] */
        $byPurchase = [];

        foreach ($lines as $line) {
            if (! in_array((int) $line['sale_item_id'], array_map('intval', $faultyLines), true)) {
                continue;
            }

            $saleItem = SaleItem::findOrFail($line['sale_item_id']);

            foreach ($this->originsFor($saleItem, (int) $line['quantity']) as $origin) {
                /*
                 * ⚠️ Nothing to send back. Opening stock and anything carried
                 * in from another room has no purchase behind it. Skipped
                 * rather than refused: the customer's return is still valid,
                 * and the screen has already said no supplier was found.
                 */
                if ($origin->purchase_item === null) {
                    continue;
                }

                $purchaseId = $origin->purchase_item->purchase_id;
                $itemId = $origin->purchase_item->id;

                $byPurchase[$purchaseId][$itemId] =
                    ($byPurchase[$purchaseId][$itemId] ?? 0) + $origin->quantity;
            }
        }

        foreach ($byPurchase as $purchaseId => $quantities) {
            $this->purchaseReturns->create(
                purchase: Purchase::findOrFail($purchaseId),
                lines: collect($quantities)
                    ->map(fn (int $quantity, int $itemId) => [
                        'purchase_item_id' => $itemId,
                        'quantity' => $quantity,
                    ])->values()->all(),
                user: $user,
                returnDate: $returnDate,
                reason: __('Faulty, returned by the customer on :document', [
                    'document' => $return->document_no,
                ]),
            );
        }
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
