<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part fitted, or the labour charged for fitting it.
 *
 * ⚠️ `unit_price` is held here rather than read off the product when the job is
 * collected: a price agreed with a customer on Monday must not change because
 * somebody edited the product on Tuesday.
 */
#[Fillable(['product_id', 'quantity', 'unit_price'])]
class RepairItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }
}
