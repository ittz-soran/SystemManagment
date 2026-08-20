<?php

namespace App\Models;

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
    'payment_method', 'paid_at', 'user_id', 'notes',
])]
class Payment extends Model
{
    use SoftDeletes;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime'];
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
