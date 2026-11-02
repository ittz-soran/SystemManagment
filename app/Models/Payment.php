<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Section 4: polymorphic, many payments per document.
 *
 * The amount is ALWAYS positive; `direction` says which way the money moved.
 * `out` is money leaving the till — a cash refund to a customer, or paying a
 * supplier. Reports read the direction so cash in and cash out stay legible.
 */
#[Fillable([
    'document_no', 'payable_type', 'payable_id', 'amount', 'direction',
    'foreign_amount', 'exchange_rate', 'rate_difference',
    'payment_method', 'paid_at', 'user_id', 'notes',
])]
class Payment extends Model
{
    use HidesArchivedPeriod, SoftDeletes;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * What a payment settled of its document, as SQL — Section 6, *A dollar
     * purchase is owed in dollars*: the dinars that left, less what the rate
     * cost beyond the document's own. Every payment not on a dollar purchase
     * has no difference, and this is its amount.
     *
     * ⚠️ Cast, because `amount` is UNSIGNED and MySQL keeps an unsigned
     * operand's result unsigned — see StockMovement::VALUE. SQLite has no
     * unsigned types and would never say.
     */
    public const SETTLED = 'CAST(amount AS SIGNED) - rate_difference';

    /** Section 8c: the column an archived period is decided by. */
    public function archivePeriodColumn(): string
    {
        return 'paid_at';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'foreign_amount' => 'integer',
            'exchange_rate' => 'integer',
            'rate_difference' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** The dinars of its document this payment settled. */
    public function settled(): int
    {
        return $this->amount - $this->rate_difference;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Resolved through the morph map registered in AppServiceProvider. */
    public function payable(): MorphTo
    {
        return $this->morphTo('payable', 'payable_type', 'payable_id');
    }
}
