<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Section 5: this model needs NO batch columns — the movements carry all of it,
 * and one source of truth cannot disagree with itself.
 */
#[Fillable(['sale_return_id', 'sale_item_id', 'product_id', 'quantity', 'unit_price'])]
class SaleReturnItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_item_id')
            ->where('reference_type', StockMovement::REF_SALE_RETURN);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }
}
