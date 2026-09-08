<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A new shop opens under its own name, and reads its settings whatever else is broken.
 *
 * Both of these were found on a real install created from the panel, on the
 * same morning, and they have the same shape: the system assuming something
 * about its surroundings that a freshly provisioned install does not provide.
 */
class ShopIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seeded name is nobody's real shop.
     *
     * It used to be 'Soran Store' — the seller's own. Every customer created
     * from the template opened their till on the first morning and read
     * somebody else's name above their sales, on screen and on every printed
     * invoice. Asserted by name so it cannot quietly come back.
     */
    public function test_the_default_shop_name_is_not_the_sellers_own(): void
    {
        $this->assertSame('My Shop', SettingSeeder::DEFAULTS['shop_name']);
        $this->assertStringNotContainsStringIgnoringCase('soran', SettingSeeder::DEFAULTS['shop_name']);
    }

    /** The name the panel typed is the name the shop opens under. */
    public function test_the_installer_names_the_shop(): void
    {
        $previous = getenv('SHOP_NAME');
        putenv('SHOP_NAME=Halabja Phone');

        try {
            Setting::query()->delete();
            Setting::flushCache();

            (new SettingSeeder)->run();

            $this->assertSame('Halabja Phone', setting('shop_name'));
        } finally {
            $previous === false ? putenv('SHOP_NAME') : putenv('SHOP_NAME='.$previous);
        }
    }

    /** With nothing given, it falls back rather than inventing or failing. */
    public function test_without_a_name_it_falls_back_to_the_default(): void
    {
        $previous = getenv('SHOP_NAME');
        putenv('SHOP_NAME=');

        try {
            Setting::query()->delete();
            Setting::flushCache();

            (new SettingSeeder)->run();

            $this->assertSame('My Shop', setting('shop_name'));
        } finally {
            $previous === false ? putenv('SHOP_NAME') : putenv('SHOP_NAME='.$previous);
        }
    }

    /**
     * An unreachable cache store must not take the shop down.
     *
     * Laravel's default cache store is the database. An install whose .env does
     * not name one therefore asks a `cache` table that does not exist until
     * migrations create it — and setting() is called from middleware on every
     * page and from the seeders themselves. The whole of `migrate --seed` died
     * on it, leaving a shop provisioned with a half-built database and nothing
     * on screen to say why.
     *
     * Reproduced by pointing the cache at a store that always throws. Reading a
     * setting has to answer from the table instead, exactly as it already
     * survived the settings table being absent.
     */
    public function test_a_setting_can_be_read_when_the_cache_store_is_unreachable(): void
    {
        Setting::put('shop_name', 'Bazaar Electronics');
        Setting::flushCache();

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('no cache table'));
        Cache::shouldReceive('forever')->andThrow(new \RuntimeException('no cache table'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('no cache table'));

        $this->assertSame('Bazaar Electronics', setting('shop_name'));
    }

    /** And saving one still works, so the shop can fix its own name. */
    public function test_a_setting_can_be_saved_when_the_cache_store_is_unreachable(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('no cache table'));
        Cache::shouldReceive('forever')->andThrow(new \RuntimeException('no cache table'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('no cache table'));

        Setting::put('shop_name', 'Renamed By The Owner');

        $this->assertSame('Renamed By The Owner', Setting::where('key', 'shop_name')->value('value'));
    }
}
