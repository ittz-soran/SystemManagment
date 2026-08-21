<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Section 8b: "An untested backup is not a backup."
 *
 * So the dump, the off-machine copy, the retention and the restore all run for
 * real here — against the test database rather than MySQL, but through the same
 * service the nightly command calls.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $local;

    private string $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->local = storage_path('framework/testing/backups-'.uniqid());
        $this->remote = storage_path('framework/testing/off-machine-'.uniqid());

        config([
            'backup.local' => $this->local,
            'backup.remote' => $this->remote,
        ]);

        // Section 8c: the Settings page owns retention now, and the seeder has
        // already written the shipped defaults, so these are set the same way
        // an admin would set them.
        Setting::put('backup_keep_daily', 3);
        Setting::put('backup_keep_monthly', 2);
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        foreach ([$this->local, $this->remote] as $directory) {
            if (is_dir($directory)) {
                $this->deleteTree($directory);
            }
        }

        parent::tearDown();
    }

    public function test_a_backup_is_written_compressed_and_copied_off_the_machine(): void
    {
        $result = app(BackupService::class)->run();

        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertStringEndsWith('.sql.gz', $result['path']);

        // Section 8b: "kept off the machine running the app."
        $this->assertNotNull($result['remote']);
        $this->assertFileExists($result['remote']);
        $this->assertSame(
            filesize($result['path']),
            filesize($result['remote']),
            'The off-machine copy is the same file, not a truncated one'
        );

        $this->assertSame([], $result['warnings']);
    }

    /** Section 8b: "a dead disk should not take both." Loud, not silent. */
    public function test_it_warns_when_no_off_machine_copy_is_configured(): void
    {
        config(['backup.remote' => null]);
        Setting::put('backup_remote_path', null);
        Setting::flushCache();

        $result = app(BackupService::class)->run();

        $this->assertNull($result['remote']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('same disk', $result['warnings'][0]);

        // The local copy is still written — a missing destination is no reason
        // to have no backup at all.
        $this->assertFileExists($result['path']);
    }

    public function test_the_settings_page_shows_the_last_backup(): void
    {
        $this->assertNull(app(BackupService::class)->lastRunAt());

        app(BackupService::class)->run();

        $this->assertNotNull(app(BackupService::class)->lastRunAt());
        $this->assertSame('ok', setting(BackupService::RESULT_KEY));

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(__('Backups'))
            ->assertSee(__('Back up now'))
            ->assertDontSee(__('Never'));
    }

    public function test_the_back_up_now_button_runs_a_backup(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('settings.backup'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertCount(1, app(BackupService::class)->copies('daily'));

        // Section 9: the activity log records every create, update and delete,
        // and a manual backup is worth the same trail.
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'module' => 'settings',
        ]);
    }

    /** Section 8b: "Retain 30 daily and 12 monthly copies." */
    public function test_dailies_are_pruned_to_the_limit_and_the_oldest_go_first(): void
    {
        $backups = app(BackupService::class);

        $made = [];

        for ($day = 1; $day <= 5; $day++) {
            Carbon::setTestNow(Carbon::parse('2026-03-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 02:15'));
            $made[] = basename($backups->run()['path']);
        }

        Carbon::setTestNow();

        $kept = array_map(fn ($file) => $file->getFilename(), $backups->copies('daily'));

        $this->assertCount(3, $kept, 'keep_daily is 3 in this test');

        // Newest first, and the two oldest are gone.
        $this->assertSame(array_reverse(array_slice($made, -3)), $kept);
        $this->assertNotContains($made[0], $kept);
    }

    /**
     * The first backup of a calendar month is also that month's keeper, so a
     * year of month-ends survives the dailies rolling off.
     */
    public function test_the_first_backup_of_a_month_is_kept_as_the_monthly_copy(): void
    {
        $backups = app(BackupService::class);

        Carbon::setTestNow(Carbon::parse('2026-03-01 02:15'));
        $backups->run();

        Carbon::setTestNow(Carbon::parse('2026-03-20 02:15'));
        $backups->run();

        Carbon::setTestNow(Carbon::parse('2026-04-02 02:15'));
        $backups->run();

        Carbon::setTestNow();

        $monthly = array_map(fn ($file) => $file->getFilename(), $backups->copies('monthly'));

        $this->assertSame(['backup-2026-04.sql.gz', 'backup-2026-03.sql.gz'], $monthly);
    }

    public function test_monthlies_are_pruned_to_the_limit(): void
    {
        $backups = app(BackupService::class);

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $date) {
            Carbon::setTestNow(Carbon::parse($date.' 02:15'));
            $backups->run();
        }

        Carbon::setTestNow();

        $monthly = array_map(fn ($file) => $file->getFilename(), $backups->copies('monthly'));

        $this->assertSame(['backup-2026-03.sql.gz', 'backup-2026-02.sql.gz'], $monthly, 'keep_monthly is 2 here');
    }

    /**
     * The whole point. A backup nobody has restored is a pile of files.
     */
    public function test_a_restore_brings_the_data_back(): void
    {
        $category = Category::create(['name' => 'Flash drives']);

        Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 7,
        ]);

        $path = app(BackupService::class)->run()['path'];

        // Something goes wrong after the backup.
        Product::query()->delete();
        Setting::put('shop_name', 'Wrong name');
        Setting::flushCache();

        $this->assertSame(0, Product::count());

        app(BackupService::class)->restore($path);

        Setting::flushCache();

        $this->assertSame(1, Product::count());
        $this->assertSame(7, Product::firstOrFail()->quantity);
        $this->assertNotSame('Wrong name', setting('shop_name'));
    }

    public function test_restoring_a_file_that_is_not_there_says_so(): void
    {
        $this->expectExceptionMessageMatches('/No backup file at/');

        app(BackupService::class)->restore($this->local.'/nope.sql.gz');
    }

    /** The nightly command is what cron actually calls. */
    public function test_the_command_runs_and_reports_where_the_backup_went(): void
    {
        $this->artisan('backup:run')
            ->assertSuccessful();

        $this->assertCount(1, app(BackupService::class)->copies('daily'));
    }

    public function test_the_restore_command_refuses_without_confirmation(): void
    {
        app(BackupService::class)->run();

        Product::query()->delete();

        $this->artisan('backup:restore')
            ->expectsConfirmation(__('Restore it?'), 'no')
            ->assertSuccessful();

        // Answering no changed nothing, so the deleted rows are still deleted.
        $this->assertSame(0, Product::count());
    }

    /**
     * "'mysqldump' is not recognized as an internal or external command" is the
     * first thing a stock XAMPP install says, because the tools live in
     * C:\xampp\mysql\bin and XAMPP does not put that on PATH.
     */
    public function test_a_missing_tool_says_where_to_find_it_and_what_to_set(): void
    {
        config(['backup.mysqldump' => 'mysqldump']);

        try {
            $this->tool('mysqldump', 'definitely-not-a-real-tool');
            $this->fail('A missing tool must be reported, not silently skipped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('xampp', $e->getMessage());
            $this->assertStringContainsString('MYSQLDUMP_PATH', $e->getMessage());
        }

        try {
            $this->tool('mysql', 'definitely-not-a-real-tool');
            $this->fail('A missing tool must be reported, not silently skipped.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MYSQL_PATH', $e->getMessage());
        }
    }

    /** An explicit path wins, so a wrong one is reported rather than worked around. */
    public function test_a_configured_path_is_used_as_given(): void
    {
        config(['backup.mysqldump' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe']);

        $this->assertSame(
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            $this->tool('mysqldump', 'mysqldump', 'mariadb-dump'),
        );
    }

    /** A tool that is on PATH is found by name, with no configuration at all. */
    public function test_a_tool_on_the_path_is_found_by_name(): void
    {
        config(['backup.mysql' => 'mysql']);

        // php is the one executable this suite can be certain is on PATH.
        $this->assertSame('php', $this->tool('mysql', 'php'));
    }

    /**
     * Section 8c: an admin changes these from the Settings page, so the setting
     * has to beat the .env default rather than the other way round.
     */
    public function test_the_settings_page_values_beat_the_env_defaults(): void
    {
        config(['backup.keep_daily' => 30, 'backup.keep_monthly' => 12, 'backup.remote' => '/from/env']);

        Setting::put('backup_keep_daily', 7);
        Setting::put('backup_keep_monthly', 4);
        Setting::put('backup_remote_path', $this->remote);
        Setting::flushCache();

        $backups = app(BackupService::class);

        $this->assertSame(7, $backups->keep('daily'));
        $this->assertSame(4, $backups->keep('monthly'));
        $this->assertSame($this->remote, $backups->remotePath());

        // And an unset one still falls back to .env.
        Setting::put('backup_remote_path', null);
        Setting::flushCache();

        $this->assertSame('/from/env', $backups->remotePath());
    }

    public function test_the_backup_folder_follows_the_setting(): void
    {
        $elsewhere = storage_path('framework/testing/chosen-'.uniqid());

        Setting::put('backup_path', $elsewhere);
        Setting::flushCache();

        try {
            $path = app(BackupService::class)->run()['path'];

            $this->assertStringStartsWith($elsewhere, $path);
            $this->assertFileExists($path);
        } finally {
            $this->deleteTree($elsewhere);
        }
    }

    /** Section 8c: daily or weekly is an admin choice, and the scheduler reads it. */
    public function test_the_schedule_follows_the_frequency_setting(): void
    {
        $this->assertFalse(app(BackupService::class)->isWeekly());

        Setting::put('backup_frequency', 'weekly');
        Setting::put('backup_weekday', 5);
        Setting::put('backup_time', '23:30');
        Setting::flushCache();

        $backups = app(BackupService::class);

        $this->assertTrue($backups->isWeekly());
        $this->assertSame(5, $backups->scheduledWeekday());
        $this->assertSame('23:30', $backups->scheduledTime());
    }

    /** A folder that cannot be written to is refused at save time, not at 02:15. */
    public function test_an_unwritable_backup_folder_is_refused_when_saved(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('settings.edit'))
            ->put(route('settings.update'), $this->settingsPayload([
                'backup_path' => '/proc/nope/definitely-not-writable',
            ]))
            ->assertSessionHasErrors('backup_path');

        $this->assertNull(setting('backup_path'));
    }

    public function test_the_backup_schedule_can_be_saved_from_the_settings_page(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('settings.update'), $this->settingsPayload([
                'backup_frequency' => 'weekly',
                'backup_weekday' => 5,
                'backup_time' => '23:45',
                'backup_keep_daily' => 14,
                'backup_keep_monthly' => 6,
                'backup_remote_path' => $this->remote,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Setting::flushCache();

        $backups = app(BackupService::class);

        $this->assertTrue($backups->isWeekly());
        $this->assertSame('23:45', $backups->scheduledTime());
        $this->assertSame(14, $backups->keep('daily'));
        $this->assertSame(6, $backups->keep('monthly'));
    }

    // ------------------------------------------------------------- diagnosing

    public function test_the_check_reports_every_link_in_the_chain(): void
    {
        $checks = collect(app(BackupService::class)->diagnose())->keyBy('name');

        $this->assertTrue($checks[__('Database')]['ok']);
        $this->assertTrue($checks[__('Backup folder')]['ok']);
        $this->assertTrue($checks[__('Off-machine copy')]['ok']);

        // Nothing has run yet in this test, and that is a finding, not a pass.
        $this->assertFalse($checks[__('Backups have run')]['ok']);
        $this->assertNotNull($checks[__('Backups have run')]['fix']);
    }

    /** Section 8b: "a dead disk should not take both" — the check says so too. */
    public function test_the_check_fails_when_no_off_machine_copy_is_set(): void
    {
        config(['backup.remote' => null]);
        Setting::put('backup_remote_path', null);
        Setting::flushCache();

        $check = collect(app(BackupService::class)->diagnose())
            ->firstWhere('name', __('Off-machine copy'));

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('dead disk', $check['fix']);
    }

    public function test_the_check_fails_on_a_folder_it_cannot_write_to(): void
    {
        Setting::put('backup_path', '/proc/nope/cannot-write-here');
        Setting::flushCache();

        $check = collect(app(BackupService::class)->diagnose())
            ->firstWhere('name', __('Backup folder'));

        $this->assertFalse($check['ok']);
    }

    public function test_the_check_passes_once_a_backup_has_run(): void
    {
        app(BackupService::class)->run();

        $check = collect(app(BackupService::class)->diagnose())
            ->firstWhere('name', __('Backups have run'));

        $this->assertTrue($check['ok']);
        $this->assertStringContainsString(__('Every night at :time', ['time' => '02:15']), $check['detail']);
    }

    public function test_the_command_exits_non_zero_when_something_is_wrong(): void
    {
        config(['backup.remote' => null]);
        Setting::put('backup_remote_path', null);
        Setting::flushCache();

        $this->artisan('backup:check')->assertFailed();

        app(BackupService::class)->run();
        Setting::put('backup_remote_path', $this->remote);
        Setting::flushCache();

        $this->artisan('backup:check')->assertSuccessful();
    }

    public function test_the_schedule_has_a_nightly_backup(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'backup:run'));

        $this->assertCount(1, $events, 'Section 8b asks for a nightly backup on a cron schedule');
    }

    /**
     * The settings form saves every layer at once, so a test that changes one
     * field still has to post the rest.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Soran Store',
            'primary_color' => '#0d6efd',
            'secondary_color' => '#6c757d',
            'font_family' => 'system-ui, sans-serif',
            'sidebar_style' => 'expanded',
            'default_theme' => 'light',
            'timezone' => 'Asia/Baghdad',
            'usd_rate' => 1320,
            'low_stock_threshold' => 5,
            'sku_prefix' => 'SS',
            'date_format' => 'Y-m-d',
            'backup_frequency' => 'daily',
            'backup_time' => '02:15',
            'backup_weekday' => 5,
            'backup_keep_daily' => 30,
            'backup_keep_monthly' => 12,
        ], $overrides);
    }

    /** BackupService::tool() is private; this is the only way to reach it. */
    private function tool(string $key, string ...$names): string
    {
        $service = app(BackupService::class);

        $method = (new \ReflectionClass($service))->getMethod('tool');
        $method->setAccessible(true);

        return $method->invoke($service, $key, ...$names);
    }

    private function deleteTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($directory);
    }
}
