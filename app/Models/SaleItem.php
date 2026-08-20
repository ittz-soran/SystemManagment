<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sale_id', 'product_id', 'quantity', 'unit_price', 'quantity_returned', 'sequence'])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'quantity_returned' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The outbound movements this line produced. One sale line can span several
     * batches, so there may be more than one.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_item_id')
            ->where('reference_type', StockMovement::REF_SALE);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }

    public function returnableQuantity(): int
    {
        return $this->quantity - $this->quantity_returned;
    }
}
