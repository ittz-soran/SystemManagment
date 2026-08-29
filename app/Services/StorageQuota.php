<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How much room this shop has left.
 *
 * The system is sold on a monthly plan with a fixed amount of space, so the
 * shopkeeper needs to see what they are using and the seller needs the figure
 * to be one they cannot quietly raise. It comes from .env on the server:
 * STORAGE_LIMIT_MB. With nothing there, there is no limit and no meter — an
 * install that was not sold on a plan should not carry a plan's furniture.
 *
 * What counts is what the shop's use actually puts on the seller's disk:
 *
 *   The database  — every sale, product, movement and ledger row.
 *   The backups   — usually the largest of the three, and the one that grows
 *                   whether the shop trades or not.
 *   The uploads   — the logo, and anything else the shop puts on the server.
 *
 * Measuring is one query and a walk of two directories, which is cheap but not
 * free, so the answer is held briefly. Everything derived from it — the meter,
 * the banner, the block — reads the same held number, so a page cannot disagree
 * with the middleware that let it load.
 */
final class StorageQuota
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const FULL = 'full';

    private const CACHE_KEY = 'storage_quota.used';

    /** Whether this install is sold on a plan at all. */
    public function isLimited(): bool
    {
        return $this->limitBytes() !== null;
    }

    /** The plan, in bytes. Null means no plan and no meter. */
    public function limitBytes(): ?int
    {
        $mb = config('quota.limit_mb');

        if ($mb === null || $mb === '' || (int) $mb <= 0) {
            return null;
        }

        return (int) $mb * 1024 * 1024;
    }

    /**
     * What the shop is using, held for a minute.
     *
     * @return array{database: int, backups: int, uploads: int, total: int}
     */
    public function breakdown(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('quota.cache_seconds', 60),
            fn () => $this->measure(),
        );
    }

    public function usedBytes(): int
    {
        return $this->breakdown()['total'];
    }

    /** Never below zero: a shop over its plan has no room left, not negative room. */
    public function remainingBytes(): int
    {
        $limit = $this->limitBytes();

        return $limit === null ? 0 : max(0, $limit - $this->usedBytes());
    }

    /** Capped at 100, so a bar that is over cannot draw past its own box. */
    public function percentUsed(): float
    {
        $limit = $this->limitBytes();

        if ($limit === null || $limit === 0) {
            return 0.0;
        }

        return min(100.0, round($this->usedBytes() / $limit * 100, 1));
    }

    /**
     * Four answers, because they need four different things from the reader.
     *
     * ok — nothing to say. warning — worth ringing somebody. critical — do it
     * today. full — the shop has stopped being able to write, and every minute
     * of that is a minute of sales going unrecorded.
     */
    public function state(): string
    {
        if (! $this->isLimited()) {
            return self::OK;
        }

        $percent = $this->usedBytes() / $this->limitBytes() * 100;

        return match (true) {
            $percent >= 100 => self::FULL,
            $percent >= (int) config('quota.critical_at', 95) => self::CRITICAL,
            $percent >= (int) config('quota.warn_at', 80) => self::WARNING,
            default => self::OK,
        };
    }

    public function isFull(): bool
    {
        return $this->state() === self::FULL;
    }

    /**
     * Measure again now.
     *
     * Called after a backup, which is the one thing that moves the figure by a
     * lot in one step — and the one thing most likely to be what tipped a shop
     * over. A meter still showing yesterday's number while the shop is being
     * refused is the worst version of this feature.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{database: int, backups: int, uploads: int, total: int} */
    private function measure(): array
    {
        $parts = [
            'database' => $this->databaseBytes(),
            'backups' => $this->directoryBytes(config('backup.local')),
            'uploads' => $this->directoryBytes(storage_path('app/public'))
                + $this->directoryBytes(storage_path('app/branding')),
        ];

        return [...$parts, 'total' => array_sum($parts)];
    }

    /**
     * What the data itself takes up.
     *
     * MySQL and MariaDB keep it in information_schema, where data_length and
     * index_length together are what the tables actually occupy — the row count
     * would say nothing about disk. SQLite is one file, so it is that file.
     * Anything else is not something this system ships on, and reporting zero
     * is better than reporting a guess.
     */
    private function databaseBytes(): int
    {
        $connection = DB::connection();

        try {
            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => (int) ($connection->selectOne(
                    'select coalesce(sum(data_length + index_length), 0) as chk_bytes '.
                    'from information_schema.tables where table_schema = database()'
                )->chk_bytes ?? 0),

                'sqlite' => $this->fileBytes($connection->getDatabaseName()),

                default => 0,
            };
        } catch (\Throwable) {
            // A permission this database user was not granted on
            // information_schema must not take the page down; a figure that is
            // missing a part is still worth the parts it has.
            return 0;
        }
    }

    private function fileBytes(?string $path): int
    {
        if (! $path || $path === ':memory:' || ! is_file($path)) {
            return 0;
        }

        return (int) (filesize($path) ?: 0);
    }

    /** Everything under a directory, including what is nested in it. */
    private function directoryBytes(?string $path): int
    {
        if (! $path || ! is_dir($path)) {
            return 0;
        }

        $bytes = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }
}
