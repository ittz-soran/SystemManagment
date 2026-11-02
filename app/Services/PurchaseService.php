<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
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
        ?int $roomId = null,
    ): Purchase {
        if ($lines === []) {
            throw new RuntimeException(__('A purchase needs at least one line.'));
        }

        if (books_closed_on($purchaseDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use (
            $supplier, $lines, $user, $purchaseDate, $discountAmount,
            $amountPaid, $supplierInvoiceNo, $exchangeRate, $paymentMethod, $roomId
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

                /*
                 * Where the delivery went — Soran, 2026-09-18. Null is the shop
                 * floor, which is what every purchase written before rooms
                 * existed meant, and what a shop with one room always means.
                 */
                'room_id' => $roomId,
                'user_id' => $user->id,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'status' => Purchase::STATUS_ACTIVE,
                'exchange_rate' => $exchangeRate,
                ...$this->owedIn($lines, $exchangeRate, $discountAmount),
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
                $paid = $this->paidWithIt($purchase, $amountPaid);

                $this->payments->record(
                    payable: $purchase,
                    amount: $amountPaid,
                    // Section 4: "direction = out is money leaving the till — a
                    // cash refund to a customer, or paying a supplier."
                    direction: Payment::DIRECTION_OUT,
                    user: $user,
                    method: $paymentMethod,
                    paidAt: $purchaseDate->copy()->setTimeFrom(now()),
                    foreignAmount: $paid['foreign'],
                    exchangeRate: $paid['foreign'] === null ? null : $exchangeRate,
                    rateDifference: $amountPaid - $paid['settled'],
                );

                // Paying the supplier reduces what the shop owes — by what the
                // payment settled, which is all of it unless the purchase is
                // owed in dollars.
                $this->ledger->post(
                    account: $supplier,
                    type: AccountTransaction::TYPE_PAYMENT,
                    amount: -$paid['settled'],
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
        ?int $roomId = null,
    ): Purchase {
        if ($lines === []) {
            throw new RuntimeException(__('A purchase needs at least one line.'));
        }

        if (books_closed_on($purchaseDate)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        return DB::transaction(function () use (
            $purchase, $supplier, $lines, $user, $purchaseDate,
            $discountAmount, $supplierInvoiceNo, $exchangeRate, $roomId
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
            $owedIn = $this->owedIn($lines, $exchangeRate, $discountAmount);

            /*
             * The same rule in dollars — Section 6, *A dollar purchase is owed
             * in dollars*. A purchase paid $16 cannot become a $12 one by an
             * edit, whatever the dinars say at a new rate.
             */
            $dollarsPaid = $purchase->foreignPaid();

            if ($owedIn['foreign_total'] !== null && $owedIn['foreign_total'] < $dollarsPaid) {
                $currency = Currency::cached()[$owedIn['foreign_currency']];
                $written = fn (int $minor) => Money::writeAt($minor, $currency->decimals).' '.$currency->mark();

                throw new RuntimeException(__(
                    'The new total of :total is less than the :paid already paid. Record a refund first.',
                    ['total' => $written($owedIn['foreign_total']), 'paid' => $written($dollarsPaid)],
                ));
            }

            // In dinars only for a purchase in dinars: a dollar purchase's
            // payments settle again below, at whatever its rate now is, and
            // the dollars above are what they must still cover.
            $cashPaid = $purchase->cashPaid();

            if ($owedIn['foreign_total'] === null && $grandTotal < $cashPaid) {
                throw new RuntimeException(__(
                    'The new total of :total is less than the :paid already paid. Record a refund first.',
                    ['total' => money($grandTotal), 'paid' => money($cashPaid)],
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
                'room_id' => $roomId,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'grand_total' => $grandTotal,
                'exchange_rate' => $exchangeRate,
                ...$owedIn,
                'purchase_date' => $purchaseDate,
            ]);

            $this->applyLines($purchase, $lines, $user, $purchaseDate);

            $this->resettle($purchase);

            // What is still owed after the edit: the new total less what was
            // already paid, posted as one figure so the ledger reads cleanly.
            $this->ledger->post(
                account: $supplier,
                type: AccountTransaction::TYPE_PURCHASE,
                amount: $grandTotal - $purchase->amountPaid(),
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

    /**
     * The currency a purchase is owed in, and its total there — Section 6,
     * *A dollar purchase is owed in dollars*.
     *
     * Each line's typed price times its quantity; a line nobody typed counts at
     * its dinars over the document's rate, as the screen showed it; less the
     * discount at the document's rate. Exact, because it is what the supplier's
     * paper says, not the dinar total divided back. Both null for a purchase in
     * the shop's own money, or one with no rate to be written at.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{foreign_currency: string|null, foreign_total: int|null}
     */
    private function owedIn(array $lines, ?int $exchangeRate, int $discountAmount): array
    {
        $none = ['foreign_currency' => null, 'foreign_total' => null];

        if (! $exchangeRate) {
            return $none;
        }

        $base = Money::base()->code;
        $currency = collect($lines)
            ->map(fn (array $line) => (string) ($line['entered_currency'] ?? ''))
            ->filter(fn (string $code) => $code !== '' && $code !== $base)
            ->map(fn (string $code) => Currency::cached()[$code] ?? null)
            ->first(fn (?Currency $currency) => $currency !== null);

        if ($currency === null) {
            return $none;
        }

        $per = $currency->minorPerMajor();
        $foreign = fn (int $dinars): int => (int) round($dinars * $per / $exchangeRate);

        $total = 0;

        foreach ($lines as $line) {
            $typed = $line['entered_amount'] ?? null;
            $unit = ($typed === null || $typed === '') ? $foreign((int) $line['unit_price']) : (int) $typed;

            $total += (int) $line['quantity'] * $unit;
        }

        return ['foreign_currency' => $currency->code, 'foreign_total' => $total - $foreign($discountAmount)];
    }

    /**
     * A payment made with the purchase itself, at the purchase's own rate.
     *
     * In dinars it settles exactly what it is. In dollars, its dollars are the
     * dinars over the rate; if they reach the whole dollar total it is the
     * closing payment, and settles every dinar — the difference is then the
     * rounding of the lines, and nothing is left owing by it.
     *
     * @return array{foreign: int|null, settled: int}
     */
    private function paidWithIt(Purchase $purchase, int $amountPaid): array
    {
        $currency = $purchase->owedIn();

        if ($currency === null) {
            return ['foreign' => null, 'settled' => $amountPaid];
        }

        $foreign = (int) round($amountPaid * $currency->minorPerMajor() / $purchase->exchange_rate);

        if ($foreign >= $purchase->foreign_total || $amountPaid >= $purchase->grand_total) {
            return ['foreign' => $purchase->foreign_total, 'settled' => $purchase->grand_total];
        }

        return ['foreign' => $foreign, 'settled' => min($amountPaid, $purchase->grand_total - 1)];
    }

    /**
     * Settle an edited purchase's payments again, in the order they were made.
     *
     * ⚠️ A payment's settlement was worked out against the purchase as it
     * stood. Edit the rate of a purchase paid $16 in full and its dinars move
     * while the dollars do not: left alone, it would read as owing dinars and
     * no dollars, and no payment could ever close it. So each payment settles
     * again by the rule it was recorded under — the one that reaches what is
     * still owed closes it — and a purchase no longer in dollars has no rate
     * difference at all. The ledger needs nothing more: the edit has already
     * reversed everything this purchase posted and posts what is owed after.
     */
    private function resettle(Purchase $purchase): void
    {
        $payments = $purchase->payments()->orderBy('paid_at')->orderBy('id')->get();
        $currency = $purchase->owedIn();

        if ($currency === null) {
            $payments->each(fn (Payment $p) => $p->forceFill(['rate_difference' => 0])->save());

            return;
        }

        $per = $currency->minorPerMajor();
        $rate = (int) $purchase->exchange_rate;
        $owedForeign = $purchase->foreign_total - $purchase->foreignCredited();
        $owed = $purchase->grand_total - $purchase->creditedByReturns();

        foreach ($payments as $payment) {
            // A payment made before the purchase was in dollars is at the
            // purchase's own rate, as the migration wrote the old ones.
            $foreign = $payment->foreign_amount ?? (int) round($payment->amount * $per / $rate);

            if ($payment->direction !== Payment::DIRECTION_OUT) {
                $settled = $payment->amount;
                $owedForeign += $foreign;
                $owed += $settled;
            } else {
                $settled = $foreign >= $owedForeign
                    ? max(0, $owed)
                    : max(0, min((int) round($foreign * $rate / $per), $owed - 1));
                $owedForeign -= $foreign;
                $owed -= $settled;
            }

            $payment->forceFill([
                'foreign_amount' => $foreign,
                'exchange_rate' => $payment->exchange_rate ?? $rate,
                'rate_difference' => $payment->amount - $settled,
            ])->save();
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

                // Where the delivery went. Null is the shop floor, which is
                // what every purchase written before rooms existed meant.
                roomId: $purchase->room_id,
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
