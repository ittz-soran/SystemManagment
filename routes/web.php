<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HeldCartController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecondHandController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

/*
 * Section 8c: the logo is read from disk and sent, rather than linked at
 * /storage/… which needs `php artisan storage:link` — a step that needs
 * administrator rights on Windows and is silently missing on most XAMPP
 * installs. Outside the auth group, because the login page shows it.
 */
Route::get('branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

Route::middleware(['auth'])->group(function () {
    /**
     * One box for the whole shop. No permission of its own: it holds none, and
     * every group inside it is behind the permission of the screen it leads to.
     */
    Route::get('search', SearchController::class)->name('search');

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    // Section 8c layer 3: every user manages their own preferences.
    Route::post('preferences/language', [PreferenceController::class, 'language'])->name('preferences.language');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');
    Route::patch('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ---- Catalogue -------------------------------------------------------
    /*
     * Section 4: a generated barcode is never printed on the goods, so the shop
     * prints its own label. Guarded by products.view — a label reveals nothing
     * that the product page does not.
     */
    Route::get('products/{product}/label', [LabelController::class, 'show'])
        ->middleware('permission:products.view')->name('products.label');
    Route::post('products/{product}/label', [LabelController::class, 'print'])
        ->middleware('permission:products.view')->name('products.label.print');

    Route::get('products/search', [ProductController::class, 'search'])
        ->middleware('permission:products.view')->name('products.search');

    Route::get('products', [ProductController::class, 'index'])
        ->middleware('permission:products.view')->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])
        ->middleware('permission:products.create')->name('products.create');
    Route::post('products', [ProductController::class, 'store'])
        ->middleware('permission:products.create')->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])
        ->middleware('permission:products.view')->name('products.show');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])
        ->middleware('permission:products.edit')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])
        ->middleware('permission:products.edit')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])
        ->middleware('permission:products.delete')->name('products.destroy');
    /*
     * A deleted product keeps its SKU and its barcode, so typing them again is
     * refused — and the only way back to the product holding them is this.
     * Guarded by products.delete: whoever may take one off the list is who may
     * put it back.
     */
    Route::post('products/{product}/restore', [ProductController::class, 'restore'])
        ->withTrashed()
        ->middleware('permission:products.delete')->name('products.restore');
    Route::delete('products', [ProductController::class, 'bulkDestroy'])
        ->middleware('permission:products.delete')->name('products.bulk-destroy');
    Route::post('products/export', [ProductController::class, 'bulkExport'])
        ->middleware('permission:products.view')->name('products.bulk-export');

    Route::get('categories', [CategoryController::class, 'index'])
        ->middleware('permission:categories.view')->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])
        ->middleware('permission:categories.create')->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])
        ->middleware('permission:categories.edit')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware('permission:categories.delete')->name('categories.destroy');
    Route::post('categories/bulk-assign', [CategoryController::class, 'bulkAssign'])
        ->middleware('permission:products.edit')->name('categories.bulk-assign');

    /*
     * A cart put down and picked up later. Nothing here touches the books:
     * no document number, no batch, no movement, no ledger row — all of that
     * waits for the real document.
     */
    Route::post('held-carts', [HeldCartController::class, 'store'])->name('held-carts.store');
    Route::delete('held-carts/{heldCart}', [HeldCartController::class, 'destroy'])->name('held-carts.destroy');

    // ---- People ----------------------------------------------------------
    Route::get('suppliers', [SupplierController::class, 'index'])
        ->middleware('permission:suppliers.view')->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])
        ->middleware('permission:suppliers.create')->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
        ->middleware('permission:suppliers.view')->name('suppliers.show');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])
        ->middleware('permission:suppliers.edit')->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
        ->middleware('permission:suppliers.delete')->name('suppliers.destroy');

    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.view')->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create')->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.view')->name('customers.show');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.edit')->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware('permission:customers.delete')->name('customers.destroy');

    /*
     * Section 9: Users — CRUD, admin only.
     *
     * `admin`, not `permission:users.view`. Whoever can save a user can save
     * one with role = admin, or set a new password on the owner's account —
     * so a permission key that opened this screen would be a permission that
     * grants every other permission. See EnsureAdmin.
     */
    Route::resource('users', UserController::class)
        ->except(['show'])
        ->middleware('admin');

    // ---- Sell & buy ------------------------------------------------------
    Route::get('sales', [SaleController::class, 'index'])
        ->middleware('permission:sales.view')->name('sales.index');
    Route::get('sales/create', [SaleController::class, 'create'])
        ->middleware('permission:sales.create')->name('sales.create');
    Route::post('sales', [SaleController::class, 'store'])
        ->middleware('permission:sales.create')->name('sales.store');
    Route::get('sales/{sale}', [SaleController::class, 'show'])
        ->middleware('permission:sales.view')->name('sales.show');
    // Section 8: editable only within 24 hours and only while nothing
    // downstream depends on it. The rules live on the model.
    Route::get('sales/{sale}/edit', [SaleController::class, 'edit'])
        ->middleware('permission:sales.edit')->name('sales.edit');
    Route::put('sales/{sale}', [SaleController::class, 'update'])
        ->middleware('permission:sales.edit')->name('sales.update');
    Route::delete('sales/{sale}', [SaleController::class, 'destroy'])
        ->middleware('permission:sales.delete')->name('sales.destroy');
    Route::delete('sales', [SaleController::class, 'bulkDestroy'])
        ->middleware('permission:sales.delete')->name('sales.bulk-destroy');

    Route::get('purchases', [PurchaseController::class, 'index'])
        ->middleware('permission:purchases.view')->name('purchases.index');
    Route::get('purchases/create', [PurchaseController::class, 'create'])
        ->middleware('permission:purchases.create')->name('purchases.create');
    Route::post('purchases', [PurchaseController::class, 'store'])
        ->middleware('permission:purchases.create')->name('purchases.store');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])
        ->middleware('permission:purchases.view')->name('purchases.show');
    Route::get('purchases/{purchase}/edit', [PurchaseController::class, 'edit'])
        ->middleware('permission:purchases.edit')->name('purchases.edit');
    Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])
        ->middleware('permission:purchases.edit')->name('purchases.update');
    Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])
        ->middleware('permission:purchases.delete')->name('purchases.destroy');
    Route::delete('purchases', [PurchaseController::class, 'bulkDestroy'])
        ->middleware('permission:purchases.delete')->name('purchases.bulk-destroy');

    // ---- Returns ---------------------------------------------------------
    // Section 7: a return creates a new forward document, so it is never
    // blocked by the edit lock.
    Route::get('sale-returns', [SaleReturnController::class, 'index'])
        ->middleware('permission:sale_returns.view')->name('sale-returns.index');
    Route::get('sales/{sale}/return', [SaleReturnController::class, 'create'])
        ->middleware('permission:sale_returns.create')->name('sale-returns.create');
    Route::post('sales/{sale}/return', [SaleReturnController::class, 'store'])
        ->middleware('permission:sale_returns.create')->name('sale-returns.store');
    Route::get('sale-returns/{saleReturn}', [SaleReturnController::class, 'show'])
        ->middleware('permission:sale_returns.view')->name('sale-returns.show');
    Route::delete('sale-returns/{saleReturn}', [SaleReturnController::class, 'destroy'])
        ->middleware('permission:sale_returns.delete')->name('sale-returns.destroy');

    Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])
        ->middleware('permission:purchase_returns.view')->name('purchase-returns.index');
    Route::get('purchases/{purchase}/return', [PurchaseReturnController::class, 'create'])
        ->middleware('permission:purchase_returns.create')->name('purchase-returns.create');
    Route::post('purchases/{purchase}/return', [PurchaseReturnController::class, 'store'])
        ->middleware('permission:purchase_returns.create')->name('purchase-returns.store');
    Route::get('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])
        ->middleware('permission:purchase_returns.view')->name('purchase-returns.show');
    Route::delete('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'destroy'])
        ->middleware('permission:purchase_returns.delete')->name('purchase-returns.destroy');

    // ---- Money -----------------------------------------------------------
    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view')->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])
        ->middleware('permission:payments.create')->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.create')->name('payments.store');
    // After payments/create, or "create" is read as a payment to look up.
    Route::get('payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('permission:payments.view')->name('payments.show');
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])
        ->middleware('permission:payments.delete')->name('payments.destroy');

    Route::get('expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expenses.view')->name('expenses.index');
    Route::post('expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expenses.create')->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('permission:expenses.edit')->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expenses.delete')->name('expenses.destroy');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('permission:expenses.view')->name('expenses.show');

    Route::middleware('permission:expense_categories.manage')->group(function () {
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
    });

    // ---- Stock adjustments -----------------------------------------------
    // Section 4: the only way to correct a locked document, so it must exist
    // before go-live.
    /**
     * Second-hand goods and services (Section 4's two kinds of row that are not
     * ordinary stock). Buying a second-hand item creates the item and buys it in
     * one act, and the act is a purchase — so that is the permission it takes.
     */
    Route::get('second-hand', [SecondHandController::class, 'index'])
        ->middleware('permission:second_hand.view')->name('second-hand.index');
    // The people the shop buys second-hand from are not suppliers and are not
    // on the suppliers screen, so seeing them is the second-hand permission.
    Route::get('second-hand/sellers', [SecondHandController::class, 'sellers'])
        ->middleware('permission:second_hand.view')->name('second-hand.sellers');
    Route::get('second-hand/sellers/search', [SecondHandController::class, 'sellerSearch'])
        ->middleware('permission:purchases.create')->name('second-hand.sellers.search');
    Route::get('second-hand/create', [SecondHandController::class, 'create'])
        ->middleware('permission:purchases.create')->name('second-hand.create');
    Route::post('second-hand', [SecondHandController::class, 'store'])
        ->middleware('permission:purchases.create')->name('second-hand.store');

    Route::get('services', [ServiceController::class, 'index'])
        ->middleware('permission:services.view')->name('services.index');
    Route::post('services', [ServiceController::class, 'store'])
        ->middleware('permission:products.create')->name('services.store');
    Route::put('services/{service}', [ServiceController::class, 'update'])
        ->middleware('permission:products.edit')->name('services.update');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])
        ->middleware('permission:products.delete')->name('services.destroy');

    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])
        ->middleware('permission:stock_adjustments.view')->name('stock-adjustments.index');
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware('permission:stock_adjustments.create')->name('stock-adjustments.store');
    Route::get('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])
        ->middleware('permission:stock_adjustments.view')->name('stock-adjustments.show');
    // Section 8's reverse-and-re-apply, the same shape as editing a purchase.
    Route::get('stock-adjustments/{stockAdjustment}/edit', [StockAdjustmentController::class, 'edit'])
        ->middleware('permission:stock_adjustments.edit')->name('stock-adjustments.edit');
    Route::put('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])
        ->middleware('permission:stock_adjustments.edit')->name('stock-adjustments.update');
    Route::delete('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'destroy'])
        ->middleware('permission:stock_adjustments.delete')->name('stock-adjustments.destroy');

    Route::middleware('permission:stock.recheck')->group(function () {
        Route::get('stock/recheck', [StockAdjustmentController::class, 'recheckStock'])->name('stock.recheck');
        Route::post('stock/repair', [StockAdjustmentController::class, 'repairStock'])->name('stock.repair');
        Route::post('balances/recalculate', [StockAdjustmentController::class, 'recalculateBalances'])->name('balances.recalculate');
    });

    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')->name('reports.index');

    /*
     * Set the dates, press the report, and what opens is the paper: the shop's
     * own letterhead on the layout every invoice already prints through.
     */
    Route::middleware('permission:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('summary', [ReportController::class, 'summary'])->name('summary');
        Route::get('sales', [ReportController::class, 'sales'])->name('sales');
        Route::get('purchases', [ReportController::class, 'purchases'])->name('purchases');
        Route::get('customers', [ReportController::class, 'customers'])->name('customers');
        Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
    });

    // ---- System ----------------------------------------------------------
    Route::get('activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('permission:activity_logs.view')->name('activity-logs.index');

    // Section 8c: the whole settings page is guarded, because these values
    // change invoices, costing and the edit window across the entire system.
    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/reset', [SettingController::class, 'reset'])->name('settings.reset');
        Route::post('settings/backup', [SettingController::class, 'backup'])->name('settings.backup');
        Route::delete('settings/transactions', [SettingController::class, 'resetTransactions'])
            ->name('settings.reset-transactions');
    });

    /*
     * Import and export the master data. Not a backup: this moves the
     * descriptive rows only.
     *
     * `data.manage` opens the screen — it used to open for anybody who could
     * look at the catalogue, which is not the same question as who may hand the
     * shop's customer list to a spreadsheet. Inside, each route still checks
     * the permission for the kind of data it touches, so holding this key does
     * not become a way round products.edit.
     */
    Route::middleware('permission:data.manage')->prefix('data')->name('data.')->group(function () {
        Route::get('/', [DataTransferController::class, 'index'])->name('index');
        // Before the {entity} routes, or "period" is read as a kind of data.
        Route::post('period/export', [DataTransferController::class, 'exportPeriod'])->name('period.export');
        Route::post('period/archive', [DataTransferController::class, 'archivePeriod'])->name('period.archive');
        Route::delete('period/archive', [DataTransferController::class, 'unarchive'])->name('period.unarchive');

        Route::get('{entity}/export', [DataTransferController::class, 'export'])->name('export');
        Route::post('{entity}/preview', [DataTransferController::class, 'preview'])->name('preview');
        Route::post('{entity}/import', [DataTransferController::class, 'import'])->name('import');
    });

    // ---- Printable documents (Section 9b) --------------------------------
    Route::get('sales/{sale}/print', [PrintController::class, 'sale'])
        ->middleware('permission:sales.view')->name('sales.print');
    Route::get('purchases/{purchase}/print', [PrintController::class, 'purchase'])
        ->middleware('permission:purchases.view')->name('purchases.print');
    Route::get('sale-returns/{saleReturn}/print', [PrintController::class, 'saleReturn'])
        ->middleware('permission:sale_returns.view')->name('sale-returns.print');
    Route::get('purchase-returns/{purchaseReturn}/print', [PrintController::class, 'purchaseReturn'])
        ->middleware('permission:purchase_returns.view')->name('purchase-returns.print');
});

require __DIR__.'/auth.php';
