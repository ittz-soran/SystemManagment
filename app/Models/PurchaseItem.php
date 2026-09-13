<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'purchase_id', 'product_id', 'quantity', 'unit_price', 'quantity_returned',
    'entered_currency', 'entered_amount', 'sequence',
])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'quantity_returned' => 'integer',
            'entered_amount' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Section 5: one purchase_item maps to exactly one batch. */
    public function batch(): HasOne
    {
        return $this->hasOne(StockBatch::class);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_price;
    }

    /** Section 7: returnable = quantity - quantity_returned, tracked cumulatively. */
    public function returnableQuantity(): int
    {
        return $this->quantity - $this->quantity_returned;
    }

    /**
     * The currency this line was typed in, when it was not the base one.
     *
     * Section 2b: what is STORED is always a base-currency integer. This is
     * only a record of what somebody typed, kept so the document can show it
     * back and so an edit reopens the box the way they left it.
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
