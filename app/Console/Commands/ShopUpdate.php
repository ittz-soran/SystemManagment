<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * Bring one shop up to date with the shared codebase.
 *
 * This is the other half of the shared-codebase decision. PANEL_DOC Section 3
 * chose one copy of the system for every shop because "an update is one upload
 * instead of one per customer", and named the failure it was avoiding: "a shop
 * running old code against a migrated database — silent, and discovered by the
 * shopkeeper rather than by Soran."
 *
 * That failure is not actually avoided by the shared folder. It is *moved*. The
 * moment `git pull` lands in the shared codebase, every shop is running the new
 * code — immediately, all of them, with no say in it — against a database that
 * has not been migrated and a public folder holding last week's stylesheet. The
 * upload stopped being per-customer; the migration never was. This command is
 * the part that finishes the job.
 *
 * Run through a shop's own artisan, which is what defines which shop this is:
 *
 *     php /home/soransto/shops/bazaar/artisan shop:update --json
 *
 * One process per shop, and that is not a limitation to be tidied away later —
 * SHOP_HOME is a constant, so a process is a shop. The panel loops over its
 * customers and spawns one of these per shop, which is also what makes a single
 * shop's failure its own rather than the batch's.
 *
 * The order is the whole design, and every step of it is reversible or safe to
 * repeat:
 *
 *   1. refuse unless this really is a shop
 *   2. say what is pending; stop here when nothing is
 *   3. back up, because step 4 changes the schema
 *   4. migrate
 *   5. drop the compiled config, routes and views — they were compiled from the
 *      code that has just been replaced
 *   6. copy the assets, files first and the manifest last
 *
 * Nothing here touches .env. That is where `shop:provision` keeps its danger —
 * a fresh APP_KEY is what stops decrypting every staff member's authenticator
 * secret — and an update has no business near it.
 */
class ShopUpdate extends Command
{
    protected $signature = 'shop:update
                            {--json : Print the result as JSON, for the panel}
                            {--no-backup : Skip the backup. Only for a shop you have just backed up by hand.}
                            {--pretend : Say what would be done, and do none of it}';

    protected $description = 'Bring this shop up to date with the shared codebase: migrate, clear caches, copy assets';

    /** What happened, step by step, for both the prose and the JSON. */
    private array $steps = [];

    public function handle(): int
    {
        if (! defined('SHOP_HOME')) {
            return $this->refuse(
                'not-a-shop',
                'This is the shared codebase, not a shop.',
                'Run it through the shop’s own artisan, which is the only thing that knows which shop it is:'
                .PHP_EOL.'  php /home/soransto/shops/<name>/artisan shop:update',
            );
        }

        $pending = $this->pendingMigrations();

        if ($pending === null) {
            return $this->refuse(
                'unreachable',
                'This shop’s database cannot be reached.',
                'Nothing was changed. Check the shop’s .env before running this again.',
            );
        }

        $assets = $this->assetsDiffer();
        $noBuild = $this->sharedBuildMissing();

        if ($noBuild) {
            // Said before anything else and repeated at the end, because a
            // shop cannot be up to date without it however green the rest is.
            $this->steps[] = [
                'step' => 'assets',
                'done' => false,
                'detail' => 'the shared codebase has no public/build — this shop is still serving its old stylesheet',
            ];
        }

        if ($pending === 0 && ! $assets && ! $noBuild) {
            $this->steps[] = ['step' => 'check', 'done' => false, 'detail' => 'already up to date'];

            return $this->finish(true, 'Already up to date.');
        }

        $this->announce($pending, $assets);

        if ($this->option('pretend')) {
            return $this->finish(true, 'Nothing was done — this was a rehearsal.');
        }

        try {
            if ($pending > 0) {
                $this->backUp();
                $this->migrate();
            }

            // Always, even when only the assets moved: a compiled view still
            // holds the old Blade, and that is what draws the screen.
            $this->clearCompiled();

            if ($assets) {
                $this->copyAssets();
            }
        } catch (Throwable $e) {
            $this->steps[] = ['step' => 'failed', 'done' => false, 'detail' => $e->getMessage()];

            return $this->finish(false, 'Stopped: '.$e->getMessage());
        }

        if ($noBuild) {
            return $this->refuse(
                'no-build',
                'The shared codebase has no public/build, so this shop’s assets were not touched.',
                'Restore it and run this again:'
                .PHP_EOL.'  cd '.base_path().' && git checkout -- public/build',
            );
        }

        return $this->finish(true, 'This shop is up to date.');
    }

    /**
     * How many migrations this shop has not run.
     *
     * Asked of the migrator rather than by reading `migrate:status --pending`,
     * because that command's answer is prose and prose lies here: on a database
     * that has never been migrated it prints "Migration table not found", which
     * a text match reads as nothing pending. That is the exact inverse of the
     * truth — everything is pending — and it would have reported a brand-new
     * customer's shop as up to date.
     *
     * Null means the database could not be reached at all, which is a different
     * answer from zero and must never be reported as "nothing to do".
     */
    private function pendingMigrations(): ?int
    {
        try {
            $migrator = $this->laravel->make('migrator');

            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')]),
            );

            // Before the repository itself exists, everything is pending — and
            // asking it would throw, since the table it lives in is created by
            // one of the migrations that has not run.
            if (! $migrator->repositoryExists()) {
                return count($files);
            }

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Has the shared codebase got a build to hand out at all?
     *
     * It always should: `public/build` is committed, so a pull carries it. It
     * can still be absent — a half-finished cleanup, a folder moved aside and
     * never moved back — and that state used to be read as "nothing to copy",
     * which is the most dangerous answer available. The command reported
     * "Already up to date" while every shop went on serving the stylesheet it
     * was provisioned with. Soran lost an afternoon to exactly that.
     */
    private function sharedBuildMissing(): bool
    {
        return ! is_file(base_path('public/build/manifest.json'));
    }

    /** Is the shop's public/build behind the shared one? */
    private function assetsDiffer(): bool
    {
        if ($this->sharedBuildMissing()) {
            return false;
        }

        $shared = base_path('public/build/manifest.json');
        $theirs = $this->shopPublic().'/build/manifest.json';

        return ! is_file($theirs)
            || hash_file('sha256', $theirs) !== hash_file('sha256', $shared);
    }

    private function shopPublic(): string
    {
        return rtrim(
            defined('SHOP_PUBLIC') ? (string) constant('SHOP_PUBLIC') : public_path(),
            '/\\',
        );
    }

    private function announce(int $pending, bool $assets): void
    {
        if ($this->option('json')) {
            return;
        }

        $this->components->info('Updating '.basename(rtrim((string) constant('SHOP_HOME'), '/\\')).'.');

        $this->line($pending > 0
            ? "  {$pending} migration(s) to run."
            : '  No migrations pending.');

        $this->line($assets
            ? '  The compiled assets are behind the shared ones.'
            : '  The compiled assets already match.');
    }

    private function backUp(): void
    {
        if ($this->option('no-backup')) {
            $this->steps[] = ['step' => 'backup', 'done' => false, 'detail' => 'skipped at your request'];

            return;
        }

        // Before the schema changes, never after. A backup taken afterwards is
        // a copy of the problem.
        if (Artisan::call('backup:run') !== self::SUCCESS) {
            throw new RuntimeException(
                'the backup failed, so the migration was not attempted. '
                .'Fix the backup, or re-run with --no-backup if you have a copy already.'
            );
        }

        $this->steps[] = ['step' => 'backup', 'done' => true, 'detail' => 'taken before migrating'];
    }

    private function migrate(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== self::SUCCESS) {
            throw new RuntimeException('the migration failed. The backup taken a moment ago is the way back.');
        }

        // The banner asks this question through a one-minute cache, so a shop
        // that has just been updated should stop warning about it now rather
        // than in a minute's time.
        app(\App\Services\SchemaVersion::class)->forget();

        $this->steps[] = ['step' => 'migrate', 'done' => true, 'detail' => 'schema brought up to date'];
    }

    /**
     * Throw away what was compiled from the code that has just been replaced.
     *
     * Cleared rather than rebuilt. `config:cache` writes the database password
     * into a file in this shop's own bootstrap/cache, and a shop that was never
     * cached should not silently acquire one because it was updated.
     */
    private function clearCompiled(): void
    {
        $cleared = [];

        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            try {
                Artisan::call($command);
                $cleared[] = explode(':', $command)[0];
            } catch (Throwable) {
                // A cache that was never written cannot be stale. Losing this
                // step is not worth losing the migration that came before it.
            }
        }

        $this->steps[] = ['step' => 'caches', 'done' => true, 'detail' => 'cleared: '.implode(', ', $cleared)];
    }

    /**
     * Copy the shared build into this shop, files first and the manifest last.
     *
     * The order is the point. Every asset is content-hashed, so a new build
     * shares no filename with the old one and the two sets can sit side by side
     * — but the manifest is what names them. Copy it first and there is a
     * window, however short, where the shop is serving a manifest pointing at
     * files that have not arrived: a blank stylesheet on a live till.
     *
     * Old files are left where they are. They are a few hundred kilobytes and
     * they are what a browser mid-page-load is still asking for.
     */
    private function copyAssets(): void
    {
        $from = base_path('public/build');
        $to = $this->shopPublic().'/build';

        if (! is_dir($to) && ! @mkdir($to, 0755, true) && ! is_dir($to)) {
            throw new RuntimeException("could not make [{$to}]. Check the folder’s permissions.");
        }

        $copied = 0;

        foreach ($this->filesIn($from.'/assets') as $file) {
            $target = $to.'/assets/'.basename($file);

            if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $file)) {
                continue;
            }

            if (! is_dir($to.'/assets') && ! @mkdir($to.'/assets', 0755, true) && ! is_dir($to.'/assets')) {
                throw new RuntimeException("could not make [{$to}/assets].");
            }

            if (! @copy($file, $target)) {
                throw new RuntimeException('could not write ['.$target.'].');
            }

            $copied++;
        }

        // Last, and only once every file it names is in place.
        if (! @copy($from.'/manifest.json', $to.'/manifest.json')) {
            throw new RuntimeException("could not write [{$to}/manifest.json].");
        }

        $this->steps[] = [
            'step' => 'assets',
            'done' => true,
            'detail' => "{$copied} file(s) copied into {$to}, manifest last",
        ];
    }

    /** @return list<string> */
    private function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($e) => $directory.'/'.$e, array_diff(scandir($directory), ['.', '..'])),
            'is_file',
        ));
    }

    /**
     * The two folders this run is actually working with.
     *
     * Reported every time, because not reporting them cost two rounds of
     * guessing: "36 file(s) copied" is worthless if the copy went somewhere no
     * web server serves. A shop whose entry point predates SHOP_PUBLIC falls
     * back to <home>/public, which on this hosting is nowhere near the
     * document root — and the only visible symptom is a shop that never
     * changes however often it is updated.
     *
     * @return array<string, string>
     */
    private function folders(): array
    {
        return [
            'home' => defined('SHOP_HOME') ? rtrim((string) constant('SHOP_HOME'), '/\\') : '',
            'public' => $this->shopPublic(),
            'public_from' => defined('SHOP_PUBLIC') ? 'SHOP_PUBLIC' : 'defaulted to <home>/public',
        ];
    }

    private function refuse(string $reason, string $message, string $advice): int
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'updated' => false,
                'reason' => $reason,
                'message' => $message,
                'steps' => $this->steps,
                ...$this->folders(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->components->error($message);
        $this->line('  '.$advice);

        return self::FAILURE;
    }

    private function finish(bool $ok, string $message): int
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'updated' => $ok,
                'reason' => $ok ? 'ok' : 'failed',
                'message' => $message,
                'steps' => $this->steps,
                ...$this->folders(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($this->steps as $step) {
            $this->line(sprintf('  %s %s — %s', $step['done'] ? '✓' : '·', $step['step'], $step['detail']));
        }

        foreach ($this->folders() as $label => $value) {
            $this->line(sprintf('  %-12s %s', $label, $value));
        }

        $ok ? $this->components->info($message) : $this->components->error($message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
