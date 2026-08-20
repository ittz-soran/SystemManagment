<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 4: money in and out of the till.
 *
 * The amount is always positive; `direction` says which way it moved. `out` is a
 * cash refund to a customer or a payment to a supplier; `in` is a customer
 * paying an invoice or cash back from a supplier.
 */
class PaymentService
{
    public function __construct(private DocumentNumberService $numbers) {}

    public function record(
        Model $payable,
        int $amount,
        string $direction,
        User $user,
        string $method = 'cash',
        ?Carbon $paidAt = null,
        ?string $notes = null,
    ): Payment {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Payments must be recorded inside a transaction.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Payment amounts are always positive; direction carries the sign.');
        }

        return Payment::create([
            'document_no' => $this->numbers->next(DocumentNumberService::PREFIX_PAYMENT),
            'payable_type' => $this->payableType($payable),
            'payable_id' => $payable->getKey(),
            'amount' => $amount,
            'direction' => $direction,
            'payment_method' => $method,
            'paid_at' => $paidAt ?? now(),
            'user_id' => $user->id,
            'notes' => $notes,
        ]);
    }

    private function payableType(Model $payable): string
    {
        return match ($payable::class) {
            Sale::class => 'sale',
            Purchase::class => 'purchase',
            SaleReturn::class => 'sale_return',
            PurchaseReturn::class => 'purchase_return',
            default => throw new RuntimeException('Unsupported payable: '.$payable::class),
        };
    }
}
