<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        ]);

        // Financial code should never silently work with a half-loaded model.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());
    }
}
