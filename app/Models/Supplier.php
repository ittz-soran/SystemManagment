<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'address', 'balance', 'is_active'])]
class Supplier extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['balance' => 'integer', 'is_active' => 'boolean'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * Section 4: the ledger is the truth; `balance` is a cache of the latest
     * balance_after.
     */
    public function accountTransactions(): MorphMany
    {
        return $this->morphMany(AccountTransaction::class, 'accountable', 'accountable_type', 'accountable_id');
    }
}
