<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

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
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     *
     * ⚠️ The whole body is guarded, not just the settings query.
     *
     * The first version guarded only `pluck()`, on the reasoning that the
     * settings table does not exist until migrations run. But the default
     * cache store is `database` — so `Cache::get()` reads a `cache` table that
     * does not exist either, one line ABOVE the try, and threw before the
     * guard was ever reached.
     *
     * That made every artisan command fail on a fresh checkout, because
     * routes/console.php reads settings at load time to schedule the backup,
     * and routes/console.php loads for every command there is. `composer
     * install` runs `package:discover` and so could not finish:
     *
     *     Database file at path [database/database.sqlite] does not exist
     *     ... SQL: select * from "cache" where "key" in (settings)
     *
     * A `.env` is not the answer either. The shared codebase the panel
     * provisions shops from is a library and a set of commands, not an
     * install — it has no shop and needs no database of its own, and
     * `shop:provision` has to run there before any database exists.
     */
    public static function cached(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }

            $values = self::query()->pluck('value', 'key')->all();

            Cache::forever(self::CACHE_KEY, $values);

            return $values;
        } catch (QueryException) {
            // No database, or no tables in it yet. Callers fall back to their
            // own defaults rather than the app failing to boot on the login
            // screen — or artisan failing to run at all.
            //
            // Deliberately not cached, so it recovers the moment the tables
            // exist, without anything needing to be flushed.
            return [];
        }
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
