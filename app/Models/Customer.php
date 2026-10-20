<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'address', 'balance', 'is_system', 'is_active', 'type'])]
class Customer extends Model
{
    use SoftDeletes;

    /**
     * The two kinds of customer besides the Cash Customer — PROJECT_DOC
     * Section 9, Soran, 2026-10-20. A government department gets its own list
     * and its sale invoice prints on A5, laid out as his legal invoice book.
     */
    public const NORMAL = 'normal';

    public const GOVERNMENT = 'government';

    public const TYPES = [self::NORMAL, self::GOVERNMENT];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function accountTransactions(): MorphMany
    {
        return $this->morphMany(AccountTransaction::class, 'accountable', 'accountable_type', 'accountable_id');
    }

    /** Section 4: the seeded walk-in row. Cannot be deleted or renamed. */
    public static function cashCustomer(): self
    {
        return self::where('is_system', true)->firstOrFail();
    }

    /**
     * What to show on screen.
     *
     * Section 4 forbids renaming the Cash Customer, so the stored name stays
     * "Cash Customer" in every language — but the label a Sorani or Arabic user
     * reads should not be the one English word left on the sale screen. Only
     * the display is localised; the row itself is untouched.
     */
    public function displayName(): string
    {
        return $this->is_system ? __('Cash Customer') : $this->name;
    }

    /**
     * ⚠️ The raw attribute, as `User::lens()` reads its own: this app runs
     * Eloquent strictly, so a customer loaded without the column would THROW
     * on `$this->type`, and the invoice is the last place that should fall over.
     */
    public function isGovernment(): bool
    {
        return ! ($this->attributes['is_system'] ?? false)
            && ($this->attributes['type'] ?? self::NORMAL) === self::GOVERNMENT;
    }
}
