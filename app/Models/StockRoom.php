<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A room the shop keeps stock in — Soran, 2026-09-15.
 *
 * ⚠️ **Exactly one room sells.** *"No pos or sale always user mainstore or
 * mainstorage while sale, second storage just holds that products are can hold
 * in main storage"*. Everything else is overflow reached by transfer, so a room
 * is not a second shop: it holds layers of the same stock, at the same costs,
 * belonging to the same books.
 */
#[Fillable(['name', 'is_main', 'is_active', 'note', 'sort_order'])]
class StockRoom extends Model
{
    use SoftDeletes;

    public const CACHE_KEY = 'stock_room_main';

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetMain());
        static::deleted(fn () => self::forgetMain());
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'room_id');
    }

    /**
     * The room the till draws from.
     *
     * ⚠️ Cached, because every sale line asks for it and the answer changes
     * perhaps once in the life of a shop. Guarded the same way `Setting::cached`
     * is: a cache that cannot be reached must never be able to stop a sale, so
     * an unreachable one falls through to the table, which is slower and
     * entirely correct.
     */
    public static function main(): self
    {
        try {
            $id = Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->where('is_main', true)->value('id'));
        } catch (Throwable) {
            $id = null;
        }

        $room = $id === null ? null : self::find($id);

        // ⚠️ Never null. A shop with no main room is a shop whose till cannot
        // find its own stock, and the migration guarantees one exists — but a
        // cache holding the id of a room somebody has since deleted would
        // otherwise take the till down rather than simply be stale.
        return $room ?? self::query()->where('is_main', true)->firstOrFail();
    }

    public static function forgetMain(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // The next read finds no cache and asks the table, which is the
            // correct answer anyway.
        }
    }

    /** Rooms goods may be moved INTO, in the order the shop arranged them. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_main')->orderBy('sort_order')->orderBy('name');
    }

    /** What this room holds right now, across every product. */
    public function unitsHeld(): int
    {
        return (int) StockBatch::where('room_id', $this->id)->sum('quantity_remaining');
    }
}
