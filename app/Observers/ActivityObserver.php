<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 4: activity_logs records every create, update and delete.
 *
 * Registered per model in AppServiceProvider rather than globally, so noisy
 * internal tables — stock_movements, stock_batches, account_transactions —
 * stay out of the log. Those already have their own audit trail; duplicating
 * them here would bury the entries Soran actually wants to read.
 */
class ActivityObserver
{
    public function __construct(private ActivityLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->logModel('create', $model);
    }

    public function updated(Model $model): void
    {
        $changed = $model->getChanges();

        // Ignore touch-only saves, and the cached columns the engine rewrites
        // on every movement — products.quantity and the balance caches would
        // otherwise flood the log with entries nobody asked for.
        //
        // Which columns those are depends on the model, and the list used to
        // not care: `quantity` is a cache on a product and the whole point of a
        // stock adjustment, so correcting an adjustment from 5 to 3 — the one
        // change most worth having a record of — went unlogged.
        $noise = ['updated_at', ...match (true) {
            $model instanceof Product => ['quantity'],
            $model instanceof Customer,
            $model instanceof Supplier => ['balance'],
            $model instanceof Sale,
            $model instanceof Purchase => ['status'],
            default => [],
        }];

        if (empty(array_diff(array_keys($changed), $noise))) {
            return;
        }

        // Section 8: every edit stores the full previous version in old_values.
        $this->logger->logModel(
            'update',
            $model,
            oldValues: array_intersect_key($model->getOriginal(), $changed),
        );
    }

    public function deleted(Model $model): void
    {
        $this->logger->logModel('delete', $model);
    }

    public function restored(Model $model): void
    {
        $this->logger->logModel('restore', $model);
    }
}
