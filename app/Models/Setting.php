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

    /** @return array<string, string|null> */
    public static function cached(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
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

        Cache::forever(self::CACHE_KEY, $values);

        return $values;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
