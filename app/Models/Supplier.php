<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'address', 'balance', 'is_active', 'is_walk_in'])]
class Supplier extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['balance' => 'integer', 'is_active' => 'boolean', 'is_walk_in' => 'boolean'];
    }

    /**
     * The suppliers the shop buys from — the ones the supplier list means.
     *
     * Someone who walked in once to sell their old console is recorded as a
     * supplier so what they are owed is tracked like anyone else's, but they are
     * not a supplier the shop deals with, and a list of two hundred of them
     * would bury the six that are.
     */
    public function scopeCompanies(Builder $query): Builder
    {
        return $query->where('is_walk_in', false);
    }

    public function scopeWalkIns(Builder $query): Builder
    {
        return $query->where('is_walk_in', true);
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
