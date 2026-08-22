<?php

namespace App\Providers;

use App\Observers\ActivityObserver;
use App\Services\ActivityLogger;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * Section 4 stores short names in the polymorphic type columns —
         * payments.payable_type, account_transactions.accountable_type — not
         * fully-qualified class names. Enforcing the map makes Eloquent's morph
         * relations read and write exactly those values, so a class rename can
         * never silently orphan historical rows.
         */
        Relation::enforceMorphMap([
            'sale' => Sale::class,
            'purchase' => Purchase::class,
            'sale_return' => SaleReturn::class,
            'purchase_return' => PurchaseReturn::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'payment' => Payment::class,

            // stock_batches.source_type and stock_movements.reference_type
            // already store this name; mapping it lets those columns be read
            // back as a relation rather than only written as a string.
            'adjustment' => StockAdjustment::class,
        ]);

        // Section 3: Bootstrap 5, so the paginator emits Bootstrap markup
        // rather than Laravel's default Tailwind classes.
        Paginator::useBootstrapFive();

        // Financial code should never silently work with a half-loaded model.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        /**
         * Section 2: every permission check goes through User::hasPermission(),
         * where admin short-circuits to true. Registering it as the Gate's
         * fallback means @can('sales.create') in a view and can:sales.create on
         * a route both consult that one method — the conditions are never
         * duplicated.
         *
         * Section 9b: nav items use this to hide links the user cannot follow,
         * so no link ever leads to "access denied".
         */
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ?: null;
        });

        $this->registerActivityLogging();
    }

    /**
     * Section 4: activity_logs records every login, create, update and delete.
     *
     * Observed per model rather than globally: stock_movements, stock_batches
     * and account_transactions already ARE the audit trail, and logging them
     * here would bury the entries a person actually wants to read.
     */
    private function registerActivityLogging(): void
    {
        $observed = [
            \App\Models\Product::class,
            \App\Models\Category::class,
            \App\Models\Customer::class,
            \App\Models\Supplier::class,
            \App\Models\User::class,
            \App\Models\Sale::class,
            \App\Models\Purchase::class,
            \App\Models\SaleReturn::class,
            \App\Models\PurchaseReturn::class,
            \App\Models\Payment::class,
            \App\Models\Expense::class,
            \App\Models\ExpenseCategory::class,
            \App\Models\StockAdjustment::class,
        ];

        foreach ($observed as $model) {
            $model::observe(ActivityObserver::class);
        }

        Event::listen(Login::class, fn (Login $event) => app(ActivityLogger::class)
            ->log('login', 'auth', $event->user->getKey(), __('Logged in'), user: $event->user));

        Event::listen(Logout::class, fn (Logout $event) => $event->user
            ? app(ActivityLogger::class)
                ->log('logout', 'auth', $event->user->getKey(), __('Logged out'), user: $event->user)
            : null);
    }
}
