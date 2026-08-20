<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_return_id', 'purchase_item_id', 'product_id',
    'quantity', 'unit_price', 'discount_share',
])]
class PurchaseReturnItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_share' => 'integer',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Section 7: the credit defaults to the full typed unit price. The discount
     * share is optional and defaults to 0 — Soran decides per return.
     */
    public function creditTotal(): int
    {
        return ($this->quantity * $this->unit_price) - $this->discount_share;
    }
}
