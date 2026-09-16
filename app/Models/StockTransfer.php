<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Goods carried from one room to another — Soran, 2026-09-15.
 *
 * ⚠️ **Nothing here is money.** A transfer has no supplier, no customer, no
 * total, no ledger entry, and changes nothing about what anything cost. The
 * shop owns exactly as much after one as before. It is a document only because
 * somebody carried twelve cartons somewhere on a Tuesday and in a month the
 * shop will want to know who.
 */
#[Fillable([
    'document_no', 'from_room_id', 'to_room_id', 'transferred_at', 'note', 'user_id',
])]
class StockTransfer extends Model
{
    use SoftDeletes;

    /** Stored with microsecond precision, same reason as StockBatch::received_at. */
    protected function transferredAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Carbon::parse($value),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s.u'),
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('sequence');
    }

    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(StockRoom::class, 'from_room_id');
    }

    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(StockRoom::class, 'to_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', StockMovement::REF_TRANSFER);
    }

    /** How many units this document moved, for a list that wants one number. */
    public function unitsMoved(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
