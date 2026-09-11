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
 * One layer of stock at one cost (Section 4).
 *
 * A batch that reaches 0 is NOT finished — a return can refill it. Never treat
 * empty batches as closed.
 */
#[Fillable([
    'product_id', 'source_type', 'source_id', 'purchase_item_id',
    'unit_cost', 'quantity_in', 'quantity_remaining', 'received_at', 'sequence',
])]
class StockBatch extends Model
{
    /**
     * What a batch's remaining units are worth, as SQL.
     *
     * `unit_cost` is an UNSIGNED BIGINT, and MySQL promotes any product it takes
     * part in to unsigned. Nothing here can go negative — a batch cannot hold
     * fewer than none — so this cast changes no answer; it exists so that every
     * `unit_cost` multiplication in the codebase looks the same and none of them
     * is a judgement call about signs. See StockMovement::VALUE, where the same
     * cast is the difference between a working report and a 500.
     */
    public const VALUE = 'quantity_remaining * CAST(unit_cost AS SIGNED)';

    /** What the batch cost when it arrived, whatever is left of it now. */
    public const PAID = 'quantity_in * CAST(unit_cost AS SIGNED)';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'quantity_in' => 'integer',
            'quantity_remaining' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * Stored with microsecond precision.
     *
     * A Carbon passed straight to the query builder is formatted by the
     * connection grammar as 'Y-m-d H:i:s', which truncates the fraction. Two
     * purchases entered in the same second would then tie on received_at, and
     * FIFO order would fall back on `sequence` — which only means anything
     * WITHIN one purchase, where every line shares a timestamp by design.
     * Formatting here keeps the doc's `received_at, sequence` rule sound.
     */
    protected function receivedAt(): Attribute
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
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Section 4: order FIFO by received_at, sequence — NEVER by id.
     *
     * `sequence` is what makes the order deterministic when the same product
     * appears twice in one purchase at two costs, sharing a timestamp.
     */
    public function scopeFifoOrder(Builder $query): Builder
    {
        return $query->orderBy('received_at')->orderBy('sequence')->orderBy('id');
    }

    public function scopeWithStock(Builder $query): Builder
    {
        return $query->where('quantity_remaining', '>', 0);
    }
}
