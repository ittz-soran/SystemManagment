<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A faulty item handed back and replaced with the same thing — Soran, 2026-09-23.
 *
 * ⚠️ The invoice it points at is NOT changed. The customer bought one and still
 * owns one; what changed is which physical unit, and the shop's shelf.
 */
#[Fillable(['sale_id', 'sale_item_id', 'product_id', 'quantity', 'note', 'swapped_at'])]
class Swap extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'replacement_cost' => 'integer',
            'faulty_cost' => 'integer',
            'swapped_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the swap cost the shop.
     *
     * The replacement came out of a batch that may have cost more than the
     * faulty one did, and the supplier only gives back what they were paid.
     * ⚠️ Positive means the shop is out of pocket, which is the ordinary case
     * when prices have risen since.
     */
    public function cost(): int
    {
        return $this->replacement_cost - $this->faulty_cost;
    }
}
