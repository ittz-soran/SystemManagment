<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'purchase_id', 'product_id', 'quantity', 'unit_price', 'quantity_returned',
    'entered_currency', 'entered_amount', 'sequence',
])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'quantity_returned' => 'integer',
            'entered_amount' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Section 5: one purchase_item maps to exactly one batch. */
    public function batch(): HasOne
    {
        return $this->hasOne(StockBatch::class);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }

    /** Section 7: returnable = quantity - quantity_returned, tracked cumulatively. */
    public function returnableQuantity(): int
    {
        return $this->quantity - $this->quantity_returned;
    }
}
