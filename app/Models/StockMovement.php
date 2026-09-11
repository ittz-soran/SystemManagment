<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The complete audit of every unit in and out (Section 4).
 *
 * SUM(quantity) per product must equal current stock. That is the check that
 * proves the books are intact.
 *
 * These rows are hard-deleted when the document that created them is reversed —
 * they are not soft-deleted, because `reverses_movement_id` bookkeeping depends
 * on absent rows really being absent.
 */
#[Fillable([
    'product_id', 'stock_batch_id', 'reference_type', 'reference_id', 'reference_item_id',
    'reverses_movement_id', 'quantity', 'unit_cost', 'occurred_at', 'sequence', 'user_id',
])]
class StockMovement extends Model
{
    /**
     * What a movement was worth, as SQL — signed, and safe on every engine.
     *
     * `quantity` is signed (a movement out of the shop is negative) and
     * `unit_cost` is an UNSIGNED BIGINT. MySQL promotes a signed-by-unsigned
     * product to unsigned, so the moment a negative quantity meets a cost the
     * whole expression wraps and the query dies with "BIGINT UNSIGNED value is
     * out of range". SQLite has no unsigned types at all, so the same SQL runs
     * perfectly in the tests and takes the shop down in the morning.
     *
     * The cast is therefore not a precaution, it is the arithmetic: use this
     * anywhere the rows being summed can include an outgoing movement.
     */
    public const VALUE = 'quantity * CAST(unit_cost AS SIGNED)';

    public const REF_PURCHASE = 'purchase';

    public const REF_SALE = 'sale';

    public const REF_SALE_RETURN = 'sale_return';

    public const REF_PURCHASE_RETURN = 'purchase_return';

    public const REF_ADJUSTMENT = 'adjustment';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /** Stored with microsecond precision, same reason as StockBatch::received_at. */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Carbon::parse($value),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s.u'),
        );
    }

    /**
     * The document this row came from.
     *
     * The type column holds a morph alias, so eager loading this resolves every
     * reference in a list with one query per type instead of one per row — which
     * matters on the product page, where a hundred movements are shown at once.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The sale movement this return movement undoes. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** The return movements that have given units back to this one. */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_movement_id');
    }

    /**
     * How many units of this outbound movement have already been given back.
     *
     * Section 5: computed from what was actually returned, never re-derived —
     * this is what lets the second, third and fourth partial returns each pick
     * up precisely where the last one left off.
     */
    public function alreadyGivenBack(): int
    {
        return (int) $this->reversals()->sum('quantity');
    }

    /** Units of this movement still available to return. */
    public function availableToReverse(): int
    {
        return abs($this->quantity) - $this->alreadyGivenBack();
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('quantity', '<', 0);
    }
}
