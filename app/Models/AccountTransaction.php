<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Section 4: the customer/supplier ledger. THIS is the truth;
 * customers.balance / suppliers.balance are caches of the latest balance_after.
 */
#[Fillable([
    'accountable_type', 'accountable_id', 'type', 'reference_type', 'reference_id',
    'amount', 'balance_after', 'user_id', 'notes',
])]
class AccountTransaction extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_REFUND = 'refund';

    public const TYPE_RETURN = 'return';

    public const TYPE_OPENING_BALANCE = 'opening_balance';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
