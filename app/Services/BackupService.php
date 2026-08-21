<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Section 8b: "Financial records are the shop's only proof of who owes what."
 *
 * Nightly mysqldump, kept off the machine running the app, 30 daily and 12
 * monthly copies, and a restore that has actually been tried. The restore side
 * lives here too rather than in a runbook, because Section 8b's other sentence
 * is the important one: an untested backup is not a backup, and a restore
 * nobody can run is not a restore.
 */
class BackupService
{
    public const STATUS_KEY = 'last_backup_at';

    public const PATH_KEY = 'last_backup_path';

    public const SIZE_KEY = 'last_backup_size';

    public const RESULT_KEY = 'last_backup_result';

    /**
     * Where mysqldump and mysql actually live when they are not on PATH.
     *
     * "'mysqldump' is not recognized as an internal or external command" is how
     * this fails on a stock XAMPP install: the tools ship with the database
     * server, in C:\xampp\mysql\bin, which XAMPP does not add to PATH. The
     * same thing bites on cron, whose PATH is much shorter than a login shell's.
     *
     * Forward slashes throughout, because PHP's glob() on Windows does not
     * handle backslashes and Windows accepts either.
     */
    private const TOOL_DIRECTORIES = [
        'C:/xampp/mysql/bin',
        'C:/laragon/bin/mysql/*/bin',
        'C:/wamp64/bin/mysql/*/bin',
        'C:/wamp64/bin/mariadb/*/bin',
        'C:/Program Files/MySQL/*/bin',
        'C:/Program Files/MariaDB */bin',
        '/opt/lampp/bin',
        '/usr/local/mysql/bin',
        '/opt/homebrew/opt/mysql-client/bin',
        '/opt/homebrew/bin',
        '/usr/local/bin',
        '/usr/bin',
    ];

    /**
     * Dump the database, copy it off the machine, then prune.
     *
     * @return array{path: string, remote: string|null, bytes: int, warnings: list<string>}
     */
    public function run(?User $user = null): array
    {
        $warnings = [];
        $startedAt = now();

        $directory = $this->directory('daily');
        $name = 'backup-'.$startedAt->format('Y-m-d-His').'.sql.gz';
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $this->dump($path);

        $bytes = (int) filesize($path);

        if ($bytes === 0) {
            @unlink($path);

            throw new RuntimeException(__('The backup came out empty and was discarded. Check the database credentials.'));
        }

        // The first backup of a calendar month is also that month's keeper, so
        // a year of month-ends survives the daily copies rolling off.
        $this->promoteMonthly($path, $startedAt);

        $remote = $this->copyOffMachine($path, $warnings);

        $this->prune('daily', $this->keep('daily'));
        $this->prune('monthly', $this->keep('monthly'));

        $this->record($startedAt, $path, $bytes, $warnings === [] ? 'ok' : 'warning');

        // Logged as a create, which is the vocabulary Section 9's activity log
        // has. A nightly cron run has no user, and ActivityLogger declines to
        // write an unattributable row, so only the "Back up now" button lands
        // here — which is the one worth an audit trail anyway.
        app(ActivityLogger::class)->log(
            action: 'create',
            module: 'settings',
            description: __('Backed up the database (:size)', ['size' => $this->humanSize($bytes)]),
            user: $user,
        );

        return ['path' => $path, 'remote' => $remote, 'bytes' => $bytes, 'warnings' => $warnings];
    }

    /**
     * Section 8b: "Test a restore before go-live and every few months after."
     *
     * Restoring overwrites everything, so the caller has to have decided that
     * on purpose — this method does not ask, and nothing calls it from the web.
     */
    public function restore(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException(__('No backup file at :path', ['path' => $path]));
        }

        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => $this->restoreMysql($path),
            'sqlite' => $this->restoreSqlite($path),
            default => throw new RuntimeException(__('Backups do not support the :driver driver.', ['driver' => $driver])),
        };
    }

    /** @return list<SplFileInfo> newest first */
    public function copies(string $kind = 'daily'): array
    {
        $files = glob($this->directory($kind).DIRECTORY_SEPARATOR.'backup-*') ?: [];

        rsort($files, SORT_STRING);

        return array_map(fn (string $file) => new SplFileInfo($file), $files);
    }

    public function lastRunAt(): ?Carbon
    {
        $value = setting(self::STATUS_KEY);

        return $value ? Carbon::parse($value) : null;
    }

    /** Where the off-machine copy goes, or null when nobody has configured one. */
    public function remotePath(): ?string
    {
        $path = setting('backup_remote_path', config('backup.remote'));

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Section 8b: "Retain 30 daily and 12 monthly copies."
     *
     * The Settings page owns these now, so an admin can keep more history on a
     * big drive without editing a file on the server. config/backup.php stays
     * the fallback for a fresh install whose settings table is not seeded yet.
     */
    public function keep(string $kind): int
    {
        $default = (int) config('backup.keep_'.$kind, $kind === 'daily' ? 30 : 12);

        return max(1, (int) setting('backup_keep_'.$kind, $default));
    }

    /** Daily or weekly, and at what time — read by the scheduler. */
    public function isWeekly(): bool
    {
        return setting('backup_frequency', 'daily') === 'weekly';
    }

    public function scheduledTime(): string
    {
        return (string) setting('backup_time', config('backup.schedule', '02:15'));
    }

    public function scheduledWeekday(): int
    {
        return (int) setting('backup_weekday', 5);
    }

    public function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $bytes < 10 && $unit !== 'B' ? 1 : 0).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }

    /**
     * Check every link in the chain, and say what to do about the one that is
     * broken.
     *
     * Written because the failures here are all indirect: a missing folder, a
     * tool that is not on PATH, a socket error that is really a missing
     * environment variable. Reading an exception message and guessing is no way
     * to find out whether the shop's records are safe.
     *
     * @return list<array{name: string, ok: bool, detail: string, fix: string|null}>
     */
    public function diagnose(): array
    {
        $checks = [];

        $driver = DB::connection()->getDriverName();
        $database = (string) DB::connection()->getConfig('database');

        try {
            DB::connection()->getPdo();
            $checks[] = $this->check(__('Database'), true, $driver.' · '.$database);
        } catch (Throwable $e) {
            return [$this->check(__('Database'), false, $e->getMessage(), __('Fix the DB_ settings in .env first — nothing else here can work until the application can reach the database.'))];
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $checks[] = $this->toolCheck(__('mysqldump'), 'mysqldump', 'mysqldump', 'mariadb-dump');
            $checks[] = $this->toolCheck(__('mysql (for restoring)'), 'mysql', 'mysql', 'mariadb');
            $checks[] = $this->connectionCheck();
        }

        $checks[] = $this->folderCheck(__('Backup folder'), rtrim((string) setting('backup_path', config('backup.local')), '/\\'), null);

        $remote = $this->remotePath();

        $checks[] = $remote === null
            ? $this->check(__('Off-machine copy'), false, __('Not set'), __('Set the off-machine folder on the Settings page to a drive or share that is not this machine. A dead disk should not take both the database and its backups.'))
            : $this->folderCheck(__('Off-machine copy'), $remote, __('Set the off-machine folder on the Settings page to a folder this account can write to.'));

        $checks[] = $this->scheduleCheck();

        return $checks;
    }

    /** @return array{name: string, ok: bool, detail: string, fix: string|null} */
    private function check(string $name, bool $ok, string $detail, ?string $fix = null): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok ? null : $fix];
    }

    /** @return array{name: string, ok: bool, detail: string, fix: string|null} */
    private function toolCheck(string $name, string $key, string ...$names): array
    {
        try {
            return $this->check($name, true, $this->tool($key, ...$names));
        } catch (RuntimeException $e) {
            return $this->check($name, false, $e->getMessage(), __(
                'Add the database server\'s bin folder to PATH, or set :setting in .env — forward slashes and no quotes.',
                ['setting' => Str::upper($key).'_PATH'],
            ));
        }
    }

    /**
     * Run the real tool against the real database, because everything above can
     * pass and this can still fail — which is exactly what a socket error is.
     *
     * @return array{name: string, ok: bool, detail: string, fix: string|null}
     */
    private function connectionCheck(): array
    {
        $name = __('mysqldump can connect');

        try {
            $tool = $this->tool('mysqldump', 'mysqldump', 'mariadb-dump');
        } catch (RuntimeException) {
            return $this->check($name, false, __('Skipped: mysqldump was not found.'));
        }

        $credentials = $this->credentialsFile();

        try {
            $process = new Process([
                $tool,
                '--defaults-extra-file='.$credentials,
                '--no-data',
                '--skip-lock-tables',
                DB::connection()->getConfig('database'),
            ], base_path(), $this->systemEnvironment());

            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                return $this->check($name, true, __('Connected and read the schema'));
            }

            $error = trim($process->getErrorOutput()) ?: __('mysqldump could not be run.');

            // 10106 is Winsock failing to initialise, which on Windows means the
            // child process is missing SystemRoot rather than anything being
            // wrong with the database or the network.
            $fix = str_contains($error, '10106') || str_contains($error, '2004')
                ? __('The socket error usually means a missing SystemRoot. Restart the web server so it picks up a full environment; if it persists, run "netsh winsock reset" as administrator and reboot.')
                : __('Check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD in .env, and that the database server is running.');

            return $this->check($name, false, $error, $fix);
        } finally {
            @unlink($credentials);
        }
    }

    /** @return array{name: string, ok: bool, detail: string, fix: string|null} */
    private function folderCheck(string $name, string $path, ?string $fix): array
    {
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            return $this->check($name, false, __('There is no folder at :path, and it could not be created.', ['path' => $path]), $fix);
        }

        return is_writable($path)
            ? $this->check($name, true, $path)
            : $this->check($name, false, __('The folder :path exists but cannot be written to.', ['path' => $path]), $fix);
    }

    /** @return array{name: string, ok: bool, detail: string, fix: string|null} */
    private function scheduleCheck(): array
    {
        $when = $this->isWeekly()
            ? __('Weekly, :day at :time', [
                'day' => \App\Http\Controllers\SettingController::weekdays()[$this->scheduledWeekday()] ?? '',
                'time' => $this->scheduledTime(),
            ])
            : __('Every night at :time', ['time' => $this->scheduledTime()]);

        $last = $this->lastRunAt();

        if ($last === null) {
            return $this->check(__('Backups have run'), false, __('Never'), __(
                'Run "php artisan backup:run" once by hand. If that works but nothing happens overnight, the scheduler is not being called — on Windows that is a Task Scheduler job running "php artisan schedule:run" every minute.'
            ));
        }

        // A backup older than two of its own intervals means the schedule is
        // not firing, whatever the settings say it should do.
        $stale = $last->lt(now()->subDays($this->isWeekly() ? 14 : 2));

        return $this->check(
            __('Backups have run'),
            ! $stale,
            $when.' · '.__('last :ago', ['ago' => $last->diffForHumans()]),
            __('The schedule says one thing and the last backup says another, so the scheduler is not being called. On Windows that is a Task Scheduler job running "php artisan schedule:run" every minute; on Linux it is one crontab line.'),
        );
    }

    // ----------------------------------------------------------------- dumping

    private function dump(string $path): void
    {
        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($path),
            // Only so the whole thing can be exercised on a test database.
            // Production is MySQL, and that is the path the doc describes.
            'sqlite' => $this->dumpSqlite($path),
            default => throw new RuntimeException(__('Backups do not support the :driver driver.', ['driver' => $driver])),
        };
    }

    private function dumpMysql(string $path): void
    {
        $credentials = $this->credentialsFile();

        try {
            // --single-transaction dumps InnoDB consistently without locking the
            // shop out mid-sale; without it a nightly backup can block the till.
            //
            // --defaults-extra-file has to come first; mysqldump ignores it
            // anywhere else.
            $this->pipeToGzip([
                $this->tool('mysqldump', 'mysqldump', 'mariadb-dump'),
                '--defaults-extra-file='.$credentials,
                '--single-transaction',
                '--quick',
                '--default-character-set=utf8mb4',
                DB::connection()->getConfig('database'),
            ], $path);
        } finally {
            @unlink($credentials);
        }
    }

    /**
     * The connection details, in a file mysqldump reads and nobody else needs to.
     *
     * The password never goes on the command line, where any other user on the
     * machine could read it out of the process list. It used to travel in
     * MYSQL_PWD instead, which was worse than it looked on Windows: handing
     * Symfony a custom environment makes it rebuild the child's environment as
     * getenv() INTERSECTED WITH $_SERVER, and under Apache $_SERVER is mostly
     * HTTP variables — so SystemRoot dropped out, Winsock could not initialise,
     * and mysqldump failed with "Can't create TCP/IP socket (10106)". A file
     * needs no custom environment at all.
     */
    private function credentialsFile(): string
    {
        $config = DB::connection()->getConfig();

        $path = tempnam(sys_get_temp_dir(), 'sm-db');

        if ($path === false) {
            throw new RuntimeException(__('Could not write to :path', ['path' => sys_get_temp_dir()]));
        }

        // Readable by this account only, on the platforms where that means
        // anything. tempnam() already creates it 0600 on Unix.
        @chmod($path, 0600);

        $quote = fn (?string $value) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';

        file_put_contents($path, implode("\n", [
            '[client]',
            'host='.$quote($config['host'] ?? '127.0.0.1'),
            'port='.(int) ($config['port'] ?? 3306),
            'user='.$quote($config['username'] ?? ''),
            'password='.$quote($config['password'] ?? ''),
            '',
        ]));

        return $path;
    }

    /**
     * The variables a child process needs on Windows, read straight from the
     * real process environment.
     *
     * Symfony composes the child's environment as getenv() intersected with
     * $_SERVER (Process::getDefaultEnv()). That is fine under the CLI and wrong
     * under Apache, where $_SERVER holds request variables rather than machine
     * ones: SystemRoot disappears, and without it Winsock cannot initialise —
     * which arrives as mysqldump error 2004, "Can't create TCP/IP socket
     * (10106)". Naming them explicitly puts them back.
     *
     * @return array<string, string>
     */
    private function systemEnvironment(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        $environment = [];

        foreach (['SystemRoot', 'SystemDrive', 'windir', 'COMSPEC', 'PATH', 'PATHEXT', 'TEMP', 'TMP'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        // Last resort: a Windows box without SystemRoot in its environment at
        // all is unusual, but the socket error it causes is baffling enough to
        // be worth guarding against.
        $environment['SystemRoot'] ??= 'C:\\Windows';

        return $environment;
    }

    /**
     * The same shape as mysqldump's output — schema, then rows, then indexes —
     * rather than a copy of the file, so the restore replays SQL exactly as the
     * MySQL one does and the two paths are not tested differently.
     */
    private function dumpSqlite(string $path): void
    {
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException(__('Could not write to :path', ['path' => $path]));
        }

        $pdo = DB::connection()->getPdo();

        $objects = DB::select(
            "select type, name, sql from sqlite_master
             where sql is not null and name not like 'sqlite_%'
             order by case type when 'table' then 0 else 1 end, name"
        );

        foreach ($objects as $object) {
            gzwrite($handle, $object->sql.";\n");

            if ($object->type !== 'table') {
                continue;
            }

            foreach (DB::table($object->name)->cursor() as $row) {
                $values = array_map(
                    fn ($value) => match (true) {
                        $value === null => 'NULL',
                        is_int($value), is_float($value) => (string) $value,
                        default => $pdo->quote((string) $value),
                    },
                    (array) $row,
                );

                gzwrite($handle, 'INSERT INTO "'.$object->name.'" VALUES ('.implode(',', $values).");\n");
            }
        }

        gzclose($handle);
    }

    /**
     * @param  list<string>  $command
     */
    private function pipeToGzip(array $command, string $path): void
    {
        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            throw new RuntimeException(__('Could not write to :path', ['path' => $path]));
        }

        $process = new Process($command, base_path(), $this->systemEnvironment());
        $process->setTimeout((float) config('backup.timeout', 900));

        try {
            // Streamed rather than buffered: a shop with years of movements
            // makes a dump far larger than the PHP memory limit.
            $process->run(function (string $type, string $chunk) use ($handle, $process) {
                if ($type === Process::OUT) {
                    gzwrite($handle, $chunk);
                }
            });
        } finally {
            gzclose($handle);
        }

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException(__('The backup failed: :error', [
                'error' => trim($process->getErrorOutput()) ?: __('mysqldump could not be run.'),
            ]));
        }
    }


    /**
     * Find one of the MySQL command-line tools.
     *
     * An explicit MYSQLDUMP_PATH/MYSQL_PATH always wins, and is used as given so
     * that a wrong one is reported rather than quietly worked around. Otherwise
     * PATH is tried first, then the places these tools are normally installed.
     *
     * @param  string  $key  the config key, which names the .env setting to suggest
     * @param  string  ...$names  the executable, then its MariaDB equivalent
     */
    private function tool(string $key, string ...$names): string
    {
        $configured = (string) config('backup.'.$key);

        // A full path is a deliberate choice. A bare name is only the default,
        // so it joins the list of things to look for rather than short-circuiting
        // the search — otherwise the default would always "win" and the lookup
        // below could never run.
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return $configured;
        }

        if ($configured !== '' && ! in_array($configured, $names, true)) {
            array_unshift($names, $configured);
        }

        foreach ($names as $name) {
            if ($this->runs($name)) {
                return $name;
            }

            foreach (self::TOOL_DIRECTORIES as $pattern) {
                foreach (glob($pattern, GLOB_NOSORT) ?: [] as $directory) {
                    foreach ([$name, $name.'.exe'] as $file) {
                        $candidate = $directory.'/'.$file;

                        if (is_file($candidate)) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        throw new RuntimeException(__(
            ':tool was not found. It ships with the database server — on XAMPP it is in C:\\xampp\\mysql\\bin. Put that folder on PATH, or set :setting in .env to the full path to it.',
            ['tool' => $names[0], 'setting' => Str::upper($key).'_PATH'],
        ));
    }

    /** Whether a name on PATH is really there and really runs. */
    private function runs(string $binary): bool
    {
        try {
            $process = new Process([$binary, '--version'], null, $this->systemEnvironment());
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            // A missing executable throws on some platforms and returns a
            // non-zero exit code on others. Both mean the same thing here.
            return false;
        }
    }

    // --------------------------------------------------------------- restoring

    private function restoreMysql(string $path): void
    {
        $credentials = $this->credentialsFile();

        try {
            $process = new Process(
                [
                    $this->tool('mysql', 'mysql', 'mariadb'),
                    '--defaults-extra-file='.$credentials,
                    '--default-character-set=utf8mb4',
                    // Otherwise the dump's DROP TABLE statements fail against a
                    // populated database as soon as one table references another.
                    '--init-command=SET FOREIGN_KEY_CHECKS=0',
                    DB::connection()->getConfig('database'),
                ],
                base_path(),
                $this->systemEnvironment(),
            );

            // Decompressed here and fed in as standard input, rather than piping
            // `gunzip -c` through a shell: there is no gunzip on Windows, and an
            // argument list needs no quoting rules that differ per platform.
            $process->setInput($this->readGzip($path));
            $process->setTimeout((float) config('backup.timeout', 900));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(__('The restore failed: :error', [
                    'error' => trim($process->getErrorOutput()) ?: __('mysql could not be run.'),
                ]));
            }
        } finally {
            @unlink($credentials);
        }
    }

    /**
     * The backup's contents, a chunk at a time — a shop with years of movements
     * makes a dump far larger than the PHP memory limit.
     *
     * @return \Generator<int, string>
     */
    private function readGzip(string $path): \Generator
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('Could not read :path', ['path' => $path]));
        }

        try {
            while (! gzeof($handle)) {
                yield (string) gzread($handle, 262144);
            }
        } finally {
            gzclose($handle);
        }
    }

    private function restoreSqlite(string $path): void
    {
        $sql = gzdecode((string) file_get_contents($path));

        if ($sql === false) {
            throw new RuntimeException(__('Could not read :path', ['path' => $path]));
        }

        $pdo = DB::connection()->getPdo();

        // Everything currently in there goes, exactly as loading a mysqldump
        // over a live database replaces it.
        //
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('PRAGMA defer_foreign_keys=ON');

        foreach (DB::select("select name from sqlite_master where type = 'view' and name not like 'sqlite_%'") as $view) {
            $pdo->exec('DROP VIEW IF EXISTS "'.$view->name.'"');
        }

        foreach ($this->sqliteDropOrder() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS "'.$table.'"');
        }

        $pdo->exec($sql);
        $pdo->exec('PRAGMA foreign_keys=ON');
    }


    /**
     * Tables in an order that can actually be dropped: nothing is removed while
     * something still points at it.
     *
     * SQLite re-reads a table's schema when a table it references goes away, so
     * dropping `users` while `permissions` still has a foreign key to it fails
     * with "no such table: main.users" — and neither foreign_keys=OFF nor
     * defer_foreign_keys=ON prevents that, because it is schema parsing rather
     * than constraint checking.
     *
     * @return list<string>
     */
    private function sqliteDropOrder(): array
    {
        $tables = array_map(
            fn ($row) => $row->name,
            DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"),
        );

        // referencedBy[parent] = the tables holding a foreign key to it.
        $referencedBy = array_fill_keys($tables, []);

        foreach ($tables as $table) {
            foreach (DB::select('pragma foreign_key_list("'.$table.'")') as $key) {
                if (isset($referencedBy[$key->table]) && $key->table !== $table) {
                    $referencedBy[$key->table][$table] = true;
                }
            }
        }

        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                fn (string $table) => $referencedBy[$table] === [],
            ));

            // A circular set of foreign keys never empties. Drop what is left in
            // whatever order it came in rather than looping forever; the pragmas
            // above cover the ordinary case, and this schema has no cycles.
            if ($ready === []) {
                return array_merge($ordered, $remaining);
            }

            foreach ($ready as $table) {
                $ordered[] = $table;

                foreach ($referencedBy as $parent => $children) {
                    unset($referencedBy[$parent][$table]);
                }
            }

            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $ordered;
    }

    // ----------------------------------------------------------------- keeping

    private function promoteMonthly(string $path, CarbonInterface $at): void
    {
        $directory = $this->directory('monthly');
        $target = $directory.DIRECTORY_SEPARATOR.'backup-'.$at->format('Y-m').'.sql.gz';

        if (! is_file($target)) {
            copy($path, $target);
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function copyOffMachine(string $path, array &$warnings): ?string
    {
        $remote = $this->remotePath();

        if ($remote === null) {
            // Section 8b: "a dead disk should not take both." Loud, not silent.
            $warnings[] = __('No off-machine copy is configured, so this backup lives on the same disk as the database. Set BACKUP_REMOTE_PATH.');

            return null;
        }

        if (! is_dir($remote) && ! @mkdir($remote, 0755, true) && ! is_dir($remote)) {
            $warnings[] = __('Could not reach the off-machine location :path. The local copy was still written.', ['path' => $remote]);

            return null;
        }

        $target = rtrim($remote, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($path);

        if (! @copy($path, $target)) {
            $warnings[] = __('Could not copy the backup to :path. The local copy was still written.', ['path' => $remote]);

            return null;
        }

        return $target;
    }

    private function prune(string $kind, int $keep): void
    {
        $files = $this->copies($kind);

        foreach (array_slice($files, max($keep, 1)) as $file) {
            @unlink($file->getPathname());
        }
    }

    private function record(CarbonInterface $at, string $path, int $bytes, string $result): void
    {
        Setting::put(self::STATUS_KEY, $at->toDateTimeString());
        Setting::put(self::PATH_KEY, $path);
        Setting::put(self::SIZE_KEY, (string) $bytes);
        Setting::put(self::RESULT_KEY, $result);

        Setting::flushCache();
    }

    private function directory(string $kind): string
    {
        $base = (string) setting('backup_path', config('backup.local'));

        $path = rtrim($base, '/\\').DIRECTORY_SEPARATOR.$kind;

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }
}
