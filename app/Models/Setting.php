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

    /** @return array<string, string|null> */
    public static function cached(): array
    {
        /*
         * The cache is an optimisation, and an optimisation may not be able to
         * take the shop down.
         *
         * Laravel's default cache store is the database, so on an install whose
         * .env does not name one, this line asks a `cache` table that does not
         * exist yet — and this method is reached from middleware on every page
         * AND from the seeders. The whole of `migrate --seed` died on it, which
         * meant a new shop provisioned with a partial .env got a half-built
         * database and no clue why.
         *
         * Reading a setting must survive every store being unavailable, exactly
         * as it already survived the settings table being absent.
         */
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            $cached = null;
        }

        try {
            $values = self::query()->pluck('value', 'key')->all();
        } catch (QueryException) {
            // On a fresh checkout the settings table does not exist until
            // migrations run, and setting() is called from middleware on every
            // page. Callers fall back to their own defaults rather than the app
            // failing to boot with a database error on the login screen.
            //
            // Deliberately not cached, so it recovers as soon as the table exists.
            return [];
        }

        try {
            Cache::forever(self::CACHE_KEY, $values);
        } catch (Throwable) {
            // Unreachable cache: answer from the table every time instead. Slower
            // and entirely correct.
        }

        return $values;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
