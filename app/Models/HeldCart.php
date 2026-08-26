<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cart put down, to be picked up later.
 *
 * It is a draft and only a draft: no document number, no batch, no movement, no
 * ledger row. Nothing in the shop's books knows this exists until the document
 * it becomes is actually saved.
 */
#[Fillable(['type', 'user_id', 'note', 'payload'])]
class HeldCart extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE = 'purchase';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** @return list<array{product_id: int, quantity: int, unit_price: int}> */
    public function lines(): array
    {
        return $this->payload['lines'] ?? [];
    }

    public function lineCount(): int
    {
        return count($this->lines());
    }

    /** What it would come to, at the prices that were typed. */
    public function total(): int
    {
        return (int) collect($this->lines())
            ->sum(fn (array $line) => ($line['quantity'] ?? 0) * ($line['unit_price'] ?? 0));
    }
}
