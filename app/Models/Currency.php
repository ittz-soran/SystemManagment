<?php

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One currency a shop can type and read in — Section 2b.
 *
 * Read on nearly every page and changed perhaps twice a year, so cached the
 * same way settings are, and busted on save. Nothing that draws a figure should
 * cost a query.
 */
#[Fillable(['code', 'name', 'symbol', 'decimals', 'rate', 'is_active'])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    public const CACHE_KEY = 'currencies';

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'rate' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    public static function flushCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Same reasoning as Setting::flushCache: a cache that cannot be
            // reached must not stop a currency being saved. The next read
            // misses and goes to the table, which is the correct answer anyway.
        }
    }

    /**
     * Every currency, keyed by code, read once and kept.
     *
     * Guarded exactly as `Setting::cached()` is, and for the same two reasons:
     * the default cache store is the database, and the shared codebase the
     * panel provisions from has no database at all. A currency lookup runs from
     * middleware, from seeders and from `routes/console.php`, which loads for
     * every artisan command there is — including `package:discover` during
     * `composer install`.
     *
     * ⚠️ **Plain arrays go into the cache, never models.** A cache store that
     * serialises hands an Eloquent object back as `__PHP_Incomplete_Class` when
     * the class is not loaded at unserialise time, and every page that draws a
     * figure then dies with a TypeError. LicenceTest caught exactly that.
     * `Setting::cached()` avoids it by storing scalars; this stores rows and
     * rebuilds the models here, which costs nothing and cannot rot across a
     * deploy that changes the class.
     *
     * @return array<string, Currency>
     */
    public static function cached(): array
    {
        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => self::read());
        } catch (Throwable) {
            try {
                $rows = self::read();
            } catch (Throwable) {
                return [];
            }
        }

        return self::build($rows) ?? self::build(self::reread()) ?? [];
    }

    /**
     * Rows into models, or null if these are not rows.
     *
     * ⚠️ The guard matters on the day this ships, not in a test. A shop's file
     * cache still holds whatever the PREVIOUS release put under this key, and
     * the previous release put something else there. Without this, the first
     * page load after a deploy is a 500 on every screen that draws a figure,
     * and the way out is a command nobody can reach because the panel is one
     * of the screens that is down.
     *
     * @param  mixed  $rows
     * @return array<string, Currency>|null
     */
    private static function build($rows): ?array
    {
        if (! is_array($rows)) {
            return null;
        }

        $built = [];

        foreach ($rows as $code => $attributes) {
            if (! is_array($attributes) || ! array_key_exists('decimals', $attributes)) {
                return null;
            }

            // newFromBuilder rather than new: it marks the model as existing, so
            // a currency taken from the cache can still be saved.
            $built[(string) $code] = (new self)->newFromBuilder($attributes);
        }

        return $built;
    }

    /** Throw the unusable cache away and ask the table. */
    private static function reread(): array
    {
        self::flushCache();

        try {
            return self::read();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(): array
    {
        return self::query()->orderBy('code')->get()
            ->keyBy('code')
            ->map(fn (self $c) => $c->getAttributes())
            ->all();
    }

    /** @param  Builder<Currency>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** What goes after a figure. The code when nobody set a symbol. */
    public function mark(): string
    {
        return ($this->symbol !== null && $this->symbol !== '') ? $this->symbol : $this->code;
    }

    /** How many minor units make one major — 100 for USD, 1 for IQD today. */
    public function minorPerMajor(): int
    {
        return 10 ** $this->decimals;
    }
}
