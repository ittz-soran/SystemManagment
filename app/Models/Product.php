<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'sku', 'barcode', 'category_id', 'unit',
    'purchase_price', 'sale_price', 'quantity', 'reorder_level', 'is_active',
])]
class Product extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'purchase_price' => 'integer',
            'sale_price' => 'integer',
            'quantity' => 'integer',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Section 4: the real stock. `products.quantity` is only a cache of this.
     * If the two ever differ, the batches win.
     */
    public function trueStock(): int
    {
        return (int) $this->stockBatches()->sum('quantity_remaining');
    }

    /**
     * Section 8c: a product with no reorder_level falls back to the global
     * low_stock_threshold setting.
     */
    public function effectiveReorderLevel(): int
    {
        return $this->reorder_level ?? (int) setting('low_stock_threshold', 0);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->effectiveReorderLevel();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Section 9b: scanning searches barcode first, then sku, then name.
     * The caller decides what to do with an exact barcode hit versus a name match.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('barcode', $term)
                ->orWhere('sku', $term)
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }
}
