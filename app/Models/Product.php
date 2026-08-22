<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'kind', 'sku', 'barcode', 'category_id', 'unit', 'condition_note',
    'acquired_from_id', 'purchase_price', 'sale_price', 'quantity',
    'reorder_level', 'is_active',
])]
class Product extends Model
{
    use SoftDeletes;

    /** Ordinary stock: many interchangeable units, costed FIFO. */
    public const KIND_STOCK = 'stock';

    /**
     * One physical second-hand thing. Its row holds one unit and one batch, so
     * the cost FIFO records against its sale is the price actually paid for it —
     * which is the only cost it has.
     */
    public const KIND_USED = 'used';

    /** Sold, never stocked: no batch, no movement, no cost. */
    public const KIND_SERVICE = 'service';

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

    /** Second-hand only: the person the shop bought this one item from. */
    public function acquiredFrom(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'acquired_from_id');
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
     * Whether this row has stock at all.
     *
     * The one question the sale, return and adjustment paths ask before they go
     * near the FIFO engine: a service has nothing to consume and nothing to put
     * back, so calling the engine for one would either write a movement for
     * goods that never existed or fail looking for a batch that never will.
     */
    public function tracksStock(): bool
    {
        return $this->kind !== self::KIND_SERVICE;
    }

    public function isService(): bool
    {
        return $this->kind === self::KIND_SERVICE;
    }

    public function isUsed(): bool
    {
        return $this->kind === self::KIND_USED;
    }

    /** A second-hand item is sold once; after that its row is history. */
    public function isSold(): bool
    {
        return $this->isUsed() && $this->quantity === 0;
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

    /** @param  string|array<int, string>  $kind */
    public function scopeOfKind(Builder $query, string|array $kind): Builder
    {
        return $query->whereIn('kind', (array) $kind);
    }

    /**
     * The ordinary catalogue. Second-hand items and services are real products
     * and sell like them, but they do not belong in a list of what the shop
     * stocks — one is a single thing that will never be reordered, the other is
     * not a thing at all.
     */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_STOCK);
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_USED);
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SERVICE);
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
