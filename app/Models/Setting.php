<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->pluck('value', 'key')->all()
        );
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
