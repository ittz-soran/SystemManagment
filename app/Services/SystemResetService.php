<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Start fresh" — clear everything that was entered while testing, and keep the
 * catalogue that was entered carefully.
 *
 * This is a go-live tool, not a housekeeping one. Once the shop is really
 * trading, Section 8b's sentence governs: financial records are the shop's only
 * proof of who owes what. So it takes a backup first, refuses once any period
 * has been frozen, and makes the person type the shop's name.
 *
 * Everything below is a HARD delete, soft-deleted rows included. A reset that
 * left the test data hidden rather than gone would defeat the point.
 */
class SystemResetService
{
    /**
     * Children before parents. Most foreign keys here are restrictOnDelete, so
     * the order is the correctness condition, not a preference.
     */
    private const ORDER = [
        'account_transactions',
        'payments',
        'stock_movements',
        'sale_return_items',
        'sale_returns',
        'purchase_return_items',
        'purchase_returns',
        'sale_items',
        'sales',
        'stock_batches',
        'purchase_items',
        'purchases',
        'stock_adjustments',
        'expenses',
    ];

    /** What survives, and is worth saying out loud on the confirmation screen. */
    public const KEPT = ['products', 'categories', 'suppliers', 'customers', 'expense_categories', 'users', 'settings'];

    public function __construct(private BackupService $backups) {}

    /**
     * How much would go. Shown before the button, never after.
     *
     * @return array<string, int>
     */
    public function preview(): array
    {
        $counts = [];

        foreach (self::ORDER as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return array_filter($counts);
    }

    /** Whether this is allowed at all, and why not when it is not. */
    public function blocker(): ?string
    {
        // A frozen period means the shop has closed a month's books. Nobody
        // does that with test data, so this is real trading history.
        if (filled(setting('books_closed_before'))) {
            return __('Books are closed up to :date, so this system is in real use. Clear that date first if you truly mean to erase everything.', [
                'date' => setting('books_closed_before'),
            ]);
        }

        return null;
    }

    /**
     * @return array{removed: array<string, int>, backup: string|null}
     */
    public function run(User $user): array
    {
        if ($blocker = $this->blocker()) {
            throw new RuntimeException($blocker);
        }

        $removed = $this->preview();

        // Before anything is touched, and outside the transaction so the file
        // survives whatever happens next. If the backup fails, so does this.
        $backup = $this->backups->run($user)['path'];

        DB::transaction(function () {
            // stock_movements points at itself through reverses_movement_id, so
            // the link goes before the rows do.
            DB::table('stock_movements')->update(['reverses_movement_id' => null]);

            foreach (self::ORDER as $table) {
                DB::table($table)->delete();
            }

            // The caches these tables fed. Section 4: products.quantity is
            // SUM(quantity_remaining) and a balance is the ledger's total —
            // with no batches and no ledger, both are zero.
            Product::query()->update(['quantity' => 0]);
            Customer::query()->update(['balance' => 0]);
            Supplier::query()->update(['balance' => 0]);

            // The first real sale should be INV-00001, not INV-00042.
            DB::table('document_counters')->update(['next_number' => 1]);

            // The testing period's audit trail goes too — but the record of
            // this reset is written after, so the wipe itself is never silent.
            DB::table('activity_logs')->delete();
        });

        app(ActivityLogger::class)->log(
            action: 'delete',
            module: 'settings',
            description: __('Cleared all transactions before going live (:summary). Backup: :backup', [
                'summary' => $this->summarise($removed),
                'backup' => basename($backup),
            ]),
            user: $user,
        );

        Setting::flushCache();

        return ['removed' => $removed, 'backup' => $backup];
    }

    /** @param  array<string, int>  $removed */
    public function summarise(array $removed): string
    {
        if ($removed === []) {
            return __('nothing to remove');
        }

        return collect($removed)
            ->map(fn (int $count, string $table) => $count.' '.str_replace('_', ' ', $table))
            ->implode(', ');
    }
}
