<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * The .sql file a new shop is set up from.
 *
 * Shared hosting has no terminal, so `migrate --seed` is not available where
 * these installs actually land — phpMyAdmin and an Import button are. The file
 * is generated rather than kept in the repository, because a kept one quietly
 * stops matching the migrations.
 *
 * Making the file needs MySQL and a scratch database, which this suite runs
 * without; what can be held here is the promise that it refuses rather than
 * doing something surprising when it cannot work, and that the command exists
 * with the options the setup notes tell people to type.
 */
class InstallSqlTest extends TestCase
{
    /** On SQLite it says so, rather than producing a file that is not MySQL. */
    public function test_it_refuses_on_a_database_it_cannot_make_a_mysql_file_from(): void
    {
        $this->assertSame('sqlite', \DB::connection()->getDriverName(), 'the suite is on SQLite');

        $this->artisan('install:sql')
            ->expectsOutputToContain('This makes a MySQL file')
            ->assertFailed();
    }

    /** The options the setup notes tell people to type all exist. */
    public function test_it_takes_the_options_the_notes_promise(): void
    {
        $definition = $this->app[Kernel::class]
            ->all()['install:sql']->getDefinition();

        foreach (['out', 'shop', 'email', 'password'] as $option) {
            $this->assertTrue($definition->hasOption($option), "--{$option} exists");
        }

        $this->assertSame('admin@example.com', $definition->getOption('email')->getDefault());
    }

    /**
     * The dump helper is on BackupService, and refuses the same way.
     *
     * It shares the tool-finding and the credentials file with the nightly
     * backup — including the XAMPP paths mysqldump hides in — rather than
     * growing a second copy of the awkward parts.
     */
    public function test_the_dump_helper_refuses_a_driver_it_cannot_dump(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL or MariaDB');

        app(BackupService::class)
            ->dumpDatabaseTo('anything', sys_get_temp_dir().'/never-written.sql');
    }
}
