<?php

namespace App\Models\Concerns;

use App\Services\PeriodArchiveService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keep an archived period out of the day-to-day lists.
 *
 * Archiving does not delete anything — the rows stay, because stock levels,
 * FIFO costs and account balances are all worked out from the complete history.
 * This only decides what a list screen shows by default.
 *
 * Deliberately a local scope rather than a global one. A global scope would
 * quietly hide the archived period from reports and from the FIFO engine too,
 * which is the exact corruption archiving is meant to avoid. Only the index
 * screens call it.
 */
trait HidesArchivedPeriod
{
    /** The column that decides which period a row belongs to. */
    abstract public function archivePeriodColumn(): string;

    public function scopeVisible(Builder $query, bool $includeArchived = false): void
    {
        $cutoff = app(PeriodArchiveService::class)->cutoff();

        if ($includeArchived || $cutoff === null) {
            return;
        }

        $query->whereDate($this->archivePeriodColumn(), '>=', $cutoff);
    }

    /** How many rows this screen is not showing, so the toggle can say so. */
    public function scopeArchivedOnly(Builder $query): void
    {
        $cutoff = app(PeriodArchiveService::class)->cutoff();

        $cutoff === null
            ? $query->whereRaw('1 = 0')
            : $query->whereDate($this->archivePeriodColumn(), '<', $cutoff);
    }
}
