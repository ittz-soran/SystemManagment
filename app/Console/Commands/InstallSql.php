<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A fresh, empty database as one .sql file, for setting a new shop up.
 *
 * Every new customer needs the same thing: the tables, the permission
 * catalogue, the settings, the document counters, the Cash Customer, and one
 * administrator. On the seller's own machine that is `migrate --seed`. On a
 * shop's hosting it is usually phpMyAdmin and an Import button, because shared
 * hosting has no terminal — so the useful shape is a file.
 *
 * It is generated rather than kept, because a file kept in the repository is a
 * file that quietly stops matching the migrations. Run it after any update and
 * the template is right again.
 *
 * The work happens in a scratch database that is created, filled, dumped and
 * dropped. Nothing touches the shop's own data at any point, and the command
 * refuses outright if the scratch name is not one it made up itself.
 */
class InstallSql extends Command
{
    protected $signature = 'install:sql
                            {--out= : Where to write the file. Defaults to storage/app/.}
                            {--shop= : The shop’s name, written into settings. Defaults to “My Shop”.}
                            {--email=admin@example.com : The administrator’s email}
                            {--password= : Their password. Omit it and one is generated and printed once.}';

    protected $description = 'Make a clean .sql file for setting up a new shop';

    public function handle(BackupService $backups): int
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("This makes a MySQL file, and this install is on [{$driver}].");
            $this->line('Run it on the machine where you develop, which is the one with MySQL.');

            return self::FAILURE;
        }

        $live = (string) DB::connection()->getConfig('database');
        $scratch = 'sm_template_'.Str::lower(Str::random(10));

        // Belt and braces. The scratch name is invented above and dropped
        // below, and the one thing that must never happen is dropping the
        // shop's own database because a name collided.
        if ($scratch === $live) {
            $this->error('Refusing to work: the scratch name matched the live database.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $out = $this->outputPath();

        // A template made without a name is still somebody's shop tomorrow, so
        // it gets a neutral one rather than the seeder's fallback.
        $shop = (string) ($this->option('shop') ?: 'My Shop');

        $this->components->info("Building a fresh database in a scratch schema [{$scratch}].");

        try {
            DB::statement("create database `{$scratch}` character set utf8mb4 collate utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            $this->error('Could not create the scratch database: '.$e->getMessage());
            $this->line('This needs an account that may CREATE DATABASE — your local MySQL root, usually.');

            return self::FAILURE;
        }

        try {
            $this->fill($scratch, $shop, $password);

            $this->components->info('Writing the file.');
            $backups->dumpDatabaseTo($scratch, $out, $this->header($shop));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Always, including after a failure: a scratch database left behind
            // is a puzzle for whoever finds it next.
            DB::statement("drop database if exists `{$scratch}`");
        }

        $this->report($out, $shop, $password);

        return self::SUCCESS;
    }

    /**
     * Run the migrations and the seeders against the scratch schema.
     *
     * The connection is redefined and made the default, because the seeders
     * work through models and models use whatever the default is. Put back
     * afterwards so nothing else in the process is surprised.
     */
    private function fill(string $scratch, string $shop, string $password): void
    {
        $original = Config::get('database.default');

        Config::set('database.connections.sm_template', array_merge(
            Config::get("database.connections.{$original}"),
            ['database' => $scratch],
        ));
        Config::set('database.default', 'sm_template');

        // ADMIN_PASSWORD is what DatabaseSeeder reads, so the account in the
        // file is the one this command was asked for rather than a random one
        // printed into a log nobody kept.
        $previous = getenv('ADMIN_PASSWORD');
        putenv('ADMIN_PASSWORD='.$password);

        // Same lever the panel uses, rather than a second way of doing it:
        // SettingSeeder reads SHOP_NAME, so the name is right from the moment
        // the row is written instead of corrected afterwards.
        $previousName = getenv('SHOP_NAME');
        putenv('SHOP_NAME='.$shop);

        try {
            DB::purge('sm_template');

            $this->components->info('Running the migrations.');
            Artisan::call('migrate', ['--database' => 'sm_template', '--force' => true], $this->output);

            $this->components->info('Seeding the permissions, settings and counters.');
            Artisan::call('db:seed', ['--database' => 'sm_template', '--force' => true], $this->output);

            if ($email = $this->option('email')) {
                User::where('email', 'admin@example.com')
                    ->update(['email' => $email]);
            }
        } finally {
            DB::purge('sm_template');
            Config::set('database.default', $original);

            $previous === false ? putenv('ADMIN_PASSWORD') : putenv('ADMIN_PASSWORD='.$previous);
            $previousName === false ? putenv('SHOP_NAME') : putenv('SHOP_NAME='.$previousName);
        }
    }

    private function outputPath(): string
    {
        $given = $this->option('out');

        if (! $given) {
            return storage_path('app/install-'.now()->format('Y-m-d').'.sql');
        }

        // A folder rather than a file: put the usual name inside it.
        return is_dir($given)
            ? rtrim($given, '/\\').DIRECTORY_SEPARATOR.'install-'.now()->format('Y-m-d').'.sql'
            : $given;
    }

    /**
     * The comment block at the top of the file.
     *
     * mysqldump's own header is turned off, because it names the scratch schema
     * it read from — a random name that would be the first thing a customer
     * sees. This says what the file is instead, and what to do with it, where
     * whoever opens it is already looking.
     */
    private function header(string $shop): string
    {
        $lines = [
            'Shop management system — a fresh, empty database.',
            '',
            'Generated '.now()->format('Y-m-d H:i'),
            '',
            'Shop name     '.$shop,
            'Administrator '.$this->option('email'),
            '',
            'It holds the tables, the permissions, the settings, the document',
            'counters, the Cash Customer and that one administrator. No products,',
            'no sales, no history: nothing of anybody else’s shop is in here.',
            '',
            'To use it: make an empty database, open it in phpMyAdmin, choose',
            'Import, and pick this file. It names no database of its own, so it',
            'goes into whichever one you import it into.',
            '',
            'Then point the site’s .env at that database and sign in as the',
            'administrator above. Change that password first thing, and set up',
            'the authenticator, before the shop starts using it.',
        ];

        return implode("\n", array_map(
            fn (string $line) => rtrim('-- '.$line),
            $lines,
        ));
    }

    private function report(string $out, string $shop, string $password): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>File</>', $out);
        $this->components->twoColumnDetail('Size', human_bytes((int) filesize($out)));
        $this->components->twoColumnDetail('Shop name', $shop);
        $this->components->twoColumnDetail('Administrator', (string) $this->option('email'));
        $this->components->twoColumnDetail('Password', $password);

        $this->newLine();
        $this->components->bulletList([
            'Write that password down. It is in the file as a hash and cannot be read back out.',
            'In phpMyAdmin: make an empty database, open it, Import, choose this file. The file names no database of its own, so it goes wherever you import it.',
            'Then set the shop’s .env — database name, user, password, APP_URL — and their licence key.',
            'Have them change the password at /profile, and set up their authenticator, before you hand it over.',
        ]);
    }
}
