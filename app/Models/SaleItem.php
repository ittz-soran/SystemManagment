<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sale_id', 'product_id', 'quantity', 'unit_price', 'entered_currency', 'entered_amount', 'quantity_returned', 'sequence'])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'quantity_returned' => 'integer',
            'quantity_swapped' => 'integer',
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

    /**
     * How many of this line can still come back.
     *
     * ⚠️ Swapped units are gone from here too — Soran, 2026-09-23. A unit
     * already handed back over the counter and replaced must not ALSO be
     * returnable, or a second unit that never existed would go onto the shelf.
     * The invoice line itself is untouched; this counter is a fact about
     * handling, not about what was sold.
     */
    public function returnableQuantity(): int
    {
        return $this->quantity - $this->quantity_returned - $this->quantity_swapped;
    }

    /**
     * The currency this line was typed in, when it was not the base one.
     *
     * ⚠️ Section 2b: what is STORED is always a base-currency integer. This is
     * only a record of what somebody typed, kept so the receipt can show it
     * back and so an edit reopens the box the way they left it. Word for word
     * the purchase side's rule — see PurchaseItem::typedIn.
     *
     * Read off `attributes` rather than the property: strict mode throws on a
     * column that was not selected, and lines are read in narrow selects.
     */
    public function typedIn(): ?Currency
    {
        $code = (string) ($this->attributes['entered_currency'] ?? '');

        if ($code === '' || $code === Money::base()->code) {
            return null;
        }

        return Currency::cached()[$code] ?? null;
    }

    /** What was typed, in that currency's own units. Zero when it was the base one. */
    public function typedAmount(): float|int
    {
        return $this->typedIn()?->asTyped($this->entered_amount) ?? 0;
    }
}
