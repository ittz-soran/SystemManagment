<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'address', 'balance', 'is_system', 'is_active'])]
class Customer extends Model
{
    use SoftDeletes;

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
}
