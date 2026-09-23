<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['sale_id', 'product_id', 'quantity', 'unit_price', 'warranty_days', 'entered_currency', 'entered_amount', 'quantity_returned', 'sequence'])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'quantity_returned' => 'integer',
            'sequence' => 'integer',
            'warranty_days' => 'integer',
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

    /**
     * The day this line's warranty runs out — Soran, 2026-09-23.
     *
     * Counted from the day it was SOLD, which is when the customer took it
     * home. (A repair counts from collection for the same reason: the clock
     * starts when the thing is in their hands, not when the shop finished
     * with it.)
     *
     * ⚠️ Read off the LINE, never the product. The product may have been
     * edited since, and what was promised on the printed invoice is what the
     * shop is held to.
     */
    public function warrantyEndsOn(): ?Carbon
    {
        if ($this->warranty_days === null) {
            return null;
        }

        return $this->sale->sale_date->copy()->addDays($this->warranty_days);
    }

    /** Is the customer still inside it today? */
    public function underWarranty(): bool
    {
        $ends = $this->warrantyEndsOn();

        return $ends !== null && $ends->gte(today());
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }

    public function returnableQuantity(): int
    {
        return $this->quantity - $this->quantity_returned;
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
