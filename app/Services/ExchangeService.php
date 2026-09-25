<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Swapping for something DIFFERENT — Soran, 2026-09-25.
 *
 * *"Swap to other product (INV changed) -> select new product and check price,
 * if same show 0 or show how much to customer repay or shop refound"*.
 *
 * ⚠️ **ONE SCREEN, TWO DOCUMENTS, AND THE SECOND ONE IS NOT OPTIONAL.** A swap
 * for the same product can leave the invoice alone, because the customer
 * bought a charger and still owns a charger — the line is true word for word.
 * The moment they walk out with something else it is not, and the damage is
 * not cosmetic: `TradeProfit` reads revenue off the sale lines **product by
 * product**, so a charger line left at 18,000 would keep money that actually
 * bought a power bank. Wrong in the one calculation the shop is judged by, and
 * invisible — both products would still add up to the right total.
 *
 * So the lines change: a sale return for what came back, a new sale for what
 * went out. One transaction, both or neither.
 */
class ExchangeService
{
    public function __construct(
        private SaleService $sales,
        private SaleReturnService $returns,
    ) {}

    /**
     * Take one product back and sell another in its place.
     *
     * @return array{sale: Sale, return: SaleReturn, settlement: int}
     *                                                                positive when the customer
     *                                                                owes, negative when the
     *                                                                shop refunds
     */
    public function create(
        SaleItem $saleItem,
        int $quantity,
        Product $wanted,
        int $wantedQuantity,
        int $wantedPrice,
        User $user,
        Carbon $on,
        int $paidNow = 0,
        string $paymentMethod = 'cash',
        bool $faulty = false,
        ?string $reason = null,
    ): array {
        if ($quantity < 1 || $wantedQuantity < 1) {
            throw new RuntimeException(__('Exchange at least one.'));
        }

        if ($quantity > $saleItem->returnableQuantity()) {
            throw new RuntimeException(__('Only :count of that line can still come back.', [
                'count' => $saleItem->returnableQuantity(),
            ]));
        }

        /*
         * ⚠️ The same product is a swap, not an exchange, and routing it here
         * would quietly undo the one thing a swap exists to protect: the
         * invoice. It would also read as a customer returning a charger and
         * buying a charger on two documents, which is a different story about
         * the same afternoon.
         */
        if ($wanted->id === $saleItem->product_id) {
            throw new RuntimeException(__('That is the same product — swap it instead, and the invoice stays as it is.'));
        }

        if ($wantedPrice < 0) {
            throw new RuntimeException(__('A price cannot be negative.'));
        }

        if (books_closed_on($on)) {
            throw new RuntimeException(__('Locked: this date is in a closed period.'));
        }

        $sale = $saleItem->sale()->firstOrFail();
        $customer = $sale->customer()->firstOrFail();

        $credit = $quantity * (int) $saleItem->unit_price;
        $charge = $wantedQuantity * $wantedPrice;

        return DB::transaction(function () use (
            $saleItem, $quantity, $wanted, $wantedQuantity, $wantedPrice, $user, $on,
            $paidNow, $paymentMethod, $faulty, $reason, $sale, $customer, $credit, $charge
        ) {
            /*
             * ⚠️ **THE NEW SALE IS WRITTEN FIRST, AND THAT ORDER IS THE WHOLE
             * SETTLEMENT.** A sale return posts its refund against what the
             * customer owes and hands back only what will not fit
             * (`LedgerService::post` returns it as `unapplied`). With the sale
             * already on the account, the credit for the old item lands on the
             * new charge and the customer settles the difference — which is
             * what an exchange IS.
             *
             * Written the other way round, the refund would walk out of the
             * till as cash and the same customer would immediately hand it
             * back for the new item: two movements for a trade in which money
             * may never have changed hands at all.
             */
            $newSale = $this->sales->create(
                customer: $customer,
                lines: [[
                    'product_id' => $wanted->id,
                    'quantity' => $wantedQuantity,
                    'unit_price' => $wantedPrice,
                ]],
                user: $user,
                saleDate: $on,
                amountPaid: $this->paidOnTheNewSale($customer, $charge, $paidNow),
                paymentMethod: $paymentMethod,
            );

            $return = $this->returns->create(
                sale: $sale,
                lines: [['sale_item_id' => $saleItem->id, 'quantity' => $quantity]],
                user: $user,
                returnDate: $on,
                reason: $reason ?? __('Exchanged for :product on :document', [
                    'product' => $wanted->name,
                    'document' => $newSale->document_no,
                ]),
                paymentMethod: $paymentMethod,
                faultyLines: $faulty ? [$saleItem->id] : [],
            );

            return ['sale' => $newSale, 'return' => $return, 'settlement' => $charge - $credit];
        });
    }

    /**
     * What the customer hands over at the counter, on the new sale.
     *
     * ⚠️ **The Cash Customer must settle in full, so an exchange on one is two
     * movements rather than none.** Section 4: a walk-in leaves nothing on
     * account, and `SaleService` refuses a system customer who has not paid the
     * whole sale. So the till takes the new price in and gives the old price
     * back, which nets to exactly the difference the screen showed — the drawer
     * is right, the books are right, and Soran only ever types one figure.
     *
     * For a named customer it is whatever they chose to pay, capped at the new
     * sale: paying more than that would make the refund bigger rather than the
     * balance smaller, which nobody means by "pay now".
     */
    private function paidOnTheNewSale(Customer $customer, int $charge, int $paidNow): int
    {
        if ($customer->is_system) {
            return $charge;
        }

        return max(0, min($paidNow, $charge));
    }
}
