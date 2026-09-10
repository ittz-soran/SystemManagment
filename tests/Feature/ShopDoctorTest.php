<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One command that answers everything I kept asking Soran to find out.
 *
 * Six rounds of remote diagnosis, six partial answers, and three wrong
 * conclusions of mine: the assets were untracked when they were deleted, the
 * shared build was missing when it was present, the database was behind when
 * it was not. Each was cheap to settle and expensive to ask about.
 *
 * What is held here is the shape of the report, because the panel parses it
 * and because a section that quietly stops being filled in is worse than one
 * that was never there — it looks like an answer.
 */
class ShopDoctorTest extends TestCase
{
    use RefreshDatabase;

    private function report(): array
    {
        Artisan::call('shop:doctor', ['--json' => true]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded, 'shop:doctor did not print JSON: '.Artisan::output());

        return $decoded;
    }

    public function test_it_reports_every_section_the_panel_reads(): void
    {
        $report = $this->report();

        foreach (['shop', 'database', 'drivers', 'assets', 'licence', 'errors'] as $section) {
            $this->assertArrayHasKey($section, $report);
        }

        foreach (['home', 'public', 'public came from', 'shared codebase', 'code version'] as $key) {
            $this->assertArrayHasKey($key, $report['shop'], "shop.{$key}");
        }
    }

    /**
     * The question that took two rounds to ask: which folder, and why that one.
     *
     * A shop whose entry point predates SHOP_PUBLIC writes its assets into a
     * private folder no web server serves, and every other signal looks
     * healthy while the shop never changes.
     */
    public function test_it_says_where_the_public_folder_came_from(): void
    {
        $report = $this->report();

        $this->assertContains(
            $report['shop']['public came from'],
            ['SHOP_PUBLIC', 'defaulted to <home>/public'],
        );
    }

    /** A healthy database is reported healthy. */
    public function test_a_migrated_database_is_missing_no_columns(): void
    {
        $database = $this->report()['database'];

        $this->assertTrue($database['reachable']);
        $this->assertSame(0, $database['migrations pending']);
        $this->assertTrue($database['up to date']);
        $this->assertSame(['none missing'], $database['users columns']);
    }

    /**
     * And a database behind the code names the columns that will break.
     *
     * This is the whole point. These exact five are what Soran's shop is
     * missing if the authenticator page and saving a preference answer 500:
     * each of those screens writes one of them.
     */
    public function test_a_database_behind_the_code_names_the_columns_that_will_break(): void
    {
        // Forget the last two migrations ran, and undo what they added.
        DB::table('migrations')->orderByDesc('id')->limit(2)->delete();

        \Illuminate\Support\Facades\Schema::table('users', function ($table) {
            $table->dropColumn(['date_language', 'clock_24_hour']);
        });

        app(\App\Services\SchemaVersion::class)->forget();

        $database = $this->report()['database'];

        $this->assertSame(2, $database['migrations pending']);
        $this->assertFalse($database['up to date']);
        $this->assertContains('date_language', $database['users columns']);
        $this->assertContains('clock_24_hour', $database['users columns']);
    }

    /** What the browser will be asked for, and whether it is there. */
    public function test_it_names_the_stylesheet_the_page_will_link_to(): void
    {
        $assets = $this->report()['assets'];

        $this->assertArrayHasKey('shared manifest', $assets);
        $this->assertArrayHasKey('shop manifest', $assets);
        $this->assertArrayHasKey('they match', $assets);
        $this->assertArrayHasKey('stylesheet', $assets);
        $this->assertArrayHasKey('stylesheet is there', $assets);

        // The committed build is what the suite runs against, so it must agree
        // with itself here or AssetBuildTest is lying too.
        $this->assertTrue($assets['they match']);
        $this->assertTrue($assets['stylesheet is there']);
    }

    /** Both defaults are `database`, and a table that has to have been migrated. */
    public function test_it_checks_that_the_session_and_cache_stores_have_what_they_need(): void
    {
        $drivers = $this->report()['drivers'];

        foreach (['session driver', 'sessions table', 'cache store', 'cache table', 'storage writable'] as $key) {
            $this->assertArrayHasKey($key, $drivers);
        }

        $this->assertNotSame('MISSING', $drivers['sessions table']);
    }

    /** It reports and it does not repair — a fix would destroy the evidence. */
    public function test_it_changes_nothing(): void
    {
        $before = DB::table('migrations')->count();
        $users = DB::table('users')->count();

        $this->report();

        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertSame($users, DB::table('users')->count());
    }
}
