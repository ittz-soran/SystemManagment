<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Section 4: the ONLY way to correct a locked document.
 * Adjustments never touch supplier or customer balances — stock moves, money does not.
 */
#[Fillable([
    'document_no', 'product_id', 'user_id', 'direction', 'quantity',
    'unit_cost', 'reason', 'notes', 'adjusted_at',
])]
class StockAdjustment extends Model
{
    use SoftDeletes;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'adjusted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
