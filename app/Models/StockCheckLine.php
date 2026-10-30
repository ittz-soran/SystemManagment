<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in a stock check, and the answer given for it — PROJECT_DOC
 * Section 9, Stock check.
 *
 * ⚠️ `expected` is what the system said when the answer was SAVED, never when
 * the round began: a round runs for days and the till keeps selling.
 */
#[Fillable(['stock_check_id', 'product_id', 'position', 'status', 'expected', 'counted', 'stock_adjustment_id', 'fix', 'note', 'fixed_at', 'checked_by', 'checked_at'])]
class StockCheckLine extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const WRONG = 'wrong';

    public const SKIPPED = 'skipped';

    /** How a wrong answer was put right, or that it is still to be. */
    public const FIX_ADJUSTED = 'adjusted';

    public const FIX_LATER = 'later';

    public const FIX_FIXED = 'fixed';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'expected' => 'integer',
            'counted' => 'integer',
            'fixed_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** The shelf less the system: −2 when two are missing. */
    public function difference(): ?int
    {
        $counted = $this->attributes['counted'] ?? null;
        $expected = $this->attributes['expected'] ?? null;

        return $counted === null || $expected === null ? null : (int) $counted - (int) $expected;
    }

    /** A wrong answer nobody has put right yet. */
    public function isToFix(): bool
    {
        return ($this->attributes['status'] ?? null) === self::WRONG
            && ($this->attributes['fix'] ?? null) === self::FIX_LATER;
    }
}
