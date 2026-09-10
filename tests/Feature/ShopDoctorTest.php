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

        foreach (['shop', 'database', 'drivers', 'dependencies', 'assets', 'licence', 'errors'] as $section) {
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

    /**
     * The libraries, because a pull never brings them.
     *
     * `vendor/` is gitignored, so a release that adds a package leaves the
     * server running source that references a class it has not got. The
     * symptom is a 500 on exactly the screens that use it and nothing
     * anywhere else — which is how the authenticator page came to fail on
     * Soran's shop while the rest of it looked perfectly healthy.
     */
    public function test_it_checks_the_php_packages_a_pull_does_not_bring(): void
    {
        $dependencies = $this->report()['dependencies'];

        $this->assertSame('present', $dependencies['vendor']);
        $this->assertArrayHasKey('composer install overdue', $dependencies);
        $this->assertSame(['none'], $dependencies['packages missing']);

        // Named on its own, because it is one screen rather than a vague state.
        $this->assertSame('present', $dependencies['qr code library']);
    }

    /**
     * "No errors" and "there is no log" are different answers.
     *
     * I printed them identically and it cost a round: a 500 that leaves
     * nothing in Laravel's log never reached Laravel, and knowing that is
     * worth more than the absence of a line.
     */
    public function test_it_says_which_logs_it_looked_in(): void
    {
        $errors = $this->report()['errors'];

        $this->assertNotEmpty($errors, 'it must always say where it looked');

        $joined = implode("\n", $errors);

        $this->assertStringContainsString('laravel', $joined);
        $this->assertStringContainsString('error_log', $joined, 'the web server writes elsewhere and that is where a fatal lands');
    }

    /**
     * The command line and the browser must be talking about the same shop.
     *
     * Two separate files name a shop — the `artisan` a command runs through
     * and the `index.php` the domain points at — and nothing compared them.
     * A shop whose two entry points disagree is diagnosed healthy from the
     * command line while the browser talks to a different install, with a
     * different database and a different log: every answer right, every answer
     * about the wrong shop.
     */
    public function test_it_compares_the_command_line_and_the_browser_entry_points(): void
    {
        $shop = $this->report()['shop'];

        $this->assertArrayHasKey('web entry point', $shop);

        // Run against the shared codebase there is nothing to compare, and
        // saying "they disagree" there would be a false alarm on every run.
        $this->assertSame('not a shop — nothing to compare', $shop['web entry point']);
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
