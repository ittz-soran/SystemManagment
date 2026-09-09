<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether this shop's database has caught up with the code it is running.
 *
 * The shared codebase makes this a real state rather than a theoretical one.
 * One `git pull` updates every shop at once, immediately, with no say in it —
 * and their databases do not move until somebody runs `shop:update` against
 * each of them. In between, the shop is running code that expects columns its
 * own database has not got.
 *
 * What that looks like from behind the counter is a 500 with no explanation,
 * on whichever screen happens to write the new column first. Soran met it four
 * times — saving preferences, the authenticator, changing language, logging
 * out — and reported four separate faults, because that is what it looks like.
 * Reproduced against real MariaDB by rolling the schema back four migrations:
 *
 *   SQLSTATE[42S22]: Unknown column 'date_language' in 'SET'
 *
 * There is no fixing that one column, because the next release will add
 * another. What can be fixed is the silence: a shop that is behind should say
 * so, on every page, to the person who can do something about it.
 *
 * Cached for a minute. The answer changes about once a month, and asking the
 * migrator costs a query and a directory scan that no page should pay for.
 */
class SchemaVersion
{
    private const CACHE_KEY = 'schema.pending_migrations';

    private const TTL = 60;

    /**
     * How many migrations this shop has not run.
     *
     * Zero when it is up to date, and zero when the question cannot be asked at
     * all — an unreachable database or an unwritable cache is somebody else's
     * emergency, and a banner is not the place to raise it.
     */
    public function pending(): int
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_int($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // An unreachable cache store must not take the page down; the
            // count is cheap enough to work out again.
        }

        $pending = $this->count();

        try {
            Cache::put(self::CACHE_KEY, $pending, self::TTL);
        } catch (Throwable) {
            // Ask every time rather than not at all.
        }

        return $pending;
    }

    public function isBehind(): bool
    {
        return $this->pending() > 0;
    }

    /** Forget the cached answer — `shop:update` calls this once it has migrated. */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // It expires by itself within the minute.
        }
    }

    private function count(): int
    {
        try {
            $migrator = app('migrator');

            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')]),
            );

            // Before the repository exists nothing has run — but that is an
            // install in progress rather than a shop that is behind, and a
            // banner on a half-built shop helps nobody.
            if (! $migrator->repositoryExists()) {
                return 0;
            }

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return 0;
        }
    }
}
