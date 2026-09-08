<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Section 8c: read on every page, changed perhaps twice a year — so cached
 * forever and busted on save. Views never touch this model directly; they use
 * the setting() helper.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const CACHE_KEY = 'settings';

    protected static function booted(): void
    {
        // A stale cache after a logo change is confusing and looks broken.
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    public static function flushCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Same reasoning as cached(): a cache that cannot be reached must
            // not stop a setting being saved. The next read finds no cache and
            // goes to the table, which is the correct answer anyway.
        }
    }

    /**
     * Every setting, read once and kept.
     *
     * ⚠️ Both the cache and the table are guarded, and for different reasons.
     *
     * **The cache**, because Laravel's default store is the database. An
     * install whose .env does not name one asks a `cache` table that does not
     * exist until migrations create it — and this method is reached from
     * middleware on every page, from the seeders, and from routes/console.php,
     * which loads for every artisan command there is. So `migrate --seed` died
     * on it and left a shop half built; `composer install` could not finish,
     * because `package:discover` is an artisan command too:
     *
     *     Database file at path [database/database.sqlite] does not exist
     *     ... SQL: select * from "cache" where "key" in (settings)
     *
     * A cache is an optimisation, and an optimisation may not be able to take
     * the shop down. An unreachable one falls through to the table, which is
     * slower and entirely correct.
     *
     * **The table**, because there may be no database at all. The shared
     * codebase the panel provisions shops from is a library and a set of
     * commands, not an install: it has no shop, needs no database of its own,
     * and `shop:provision` has to run there before any database exists. A `.env`
     * is not the answer to that.
     *
     * Nothing is cached on the way out of either failure, so it recovers the
     * moment the tables exist, without anything needing to be flushed.
     *
     * @return array<string, string|null>
     */
    public static function cached(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Unreachable store. Answer from the table instead.
        }

        try {
            $values = self::query()->pluck('value', 'key')->all();
        } catch (QueryException) {
            // No database, or no tables in it yet. Callers fall back to their
            // own defaults rather than the app failing to boot on the login
            // screen — or artisan failing to run at all.
            return [];
        }

        try {
            Cache::forever(self::CACHE_KEY, $values);
        } catch (Throwable) {
            // Read every time rather than not at all.
        }

        return $values;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
