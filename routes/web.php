<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\AuthenticatorController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataCheckController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FindController;
use App\Http\Controllers\GoodsBackController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\HeldCartController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\RemembranceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecondHandController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockRoomController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SwapController;
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

/*
 * Section 9b: the shop on a phone's home screen.
 *
 * All three outside the auth group, and they have to be: a phone fetches the
 * manifest and the icon while the login page is on the screen, before anybody
 * has signed in, and a service worker is registered for the whole site rather
 * than for a session. None of them says anything the login page does not
 * already say — the shop's name, its colour and its logo.
 */
Route::get('manifest.webmanifest', [InstallController::class, 'manifest'])->name('install.manifest');
Route::get('app-icon-{size}.png', [InstallController::class, 'icon'])
    ->whereNumber('size')->name('install.icon');
Route::get('sw.js', [InstallController::class, 'serviceWorker'])->name('install.worker');

Route::middleware(['auth'])->group(function () {
    /*
     * Putting the first-week checklist away.
     *
     * No permission of its own: it is on the dashboard, which every reader can
     * open, and the card is only ever shown to an admin in the first place.
     */
    Route::delete('setup-checklist', [DashboardController::class, 'hideSetup'])
        ->name('setup.hide');

    /**
     * One box for the whole shop. No permission of its own: it holds none, and
     * every group inside it is behind the permission of the screen it leads to.
     */
    Route::get('search', SearchController::class)->name('search');

    /*
     * The find page — one box, and everything the shop knows about the answer.
     *
     * ⚠️ No permission on the route. The page itself reveals nothing: every
     * panel inside is behind the permission of the screen it summarises, and a
     * reader with none of them gets a box and an empty answer. Guarding the
     * route instead would mean inventing a key for "may look things up", which
     * is every job in the shop.
     */
    Route::get('find/suggest', [FindController::class, 'suggest'])->name('find.suggest');
    Route::get('find', FindController::class)->name('find');

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    // Section 8c layer 3: every user manages their own preferences.
    Route::post('preferences/language', [PreferenceController::class, 'language'])->name('preferences.language');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');
    Route::post('preferences/currency', [PreferenceController::class, 'currency'])->name('preferences.currency');
    Route::patch('preferences', [PreferenceController::class, 'update'])->name('preferences.update');
    Route::post('preferences/notifications', [PreferenceController::class, 'notifications'])
        ->name('preferences.notifications');

    /*
     * The bell.
     *
     * ⚠️ No permission on any of the three, deliberately. Every signed-in
     * person has a bell; what it is allowed to say is decided entry by entry in
     * NotificationFeed, against the permissions that person already holds. A
     * `permission:` here would be the wrong question asked in the wrong place —
     * and would leave the reader who holds the fewest permissions, the one most
     * likely to miss something, with no bell at all.
     */
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('notifications/seen', [NotificationController::class, 'seen'])->name('notifications.seen');

    /*
     * A phone asking to be buzzed with the app closed — Soran, 2026-09-17.
     *
     * No permission, like the rest of the bell: every signed-in person may ask
     * for their own device to be notified, and what it is allowed to say is
     * decided per entry by the same rules the bell uses.
     */
    Route::post('notifications/device', [NotificationController::class, 'subscribe'])
        ->name('notifications.subscribe');
    Route::delete('notifications/device', [NotificationController::class, 'unsubscribe'])
        ->name('notifications.unsubscribe');

    /*
     * The remembrances — أذكار — asked for 2026-09-15.
     *
     * No permission and nothing to save: the list belongs to the shop and is
     * edited in Settings, and the tally beside each line never leaves the
     * reader's own browser.
     */
    Route::get('remembrance', [RemembranceController::class, 'index'])->name('remembrance.index');
    Route::post('preferences/remembrance', [PreferenceController::class, 'remembrance'])
        ->name('preferences.remembrance');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /*
     * The phone that becomes the way back in when a password is forgotten.
     *
     * Everybody's own, not an admin's to set up for them: the secret has to
     * reach a phone the person actually holds, or it is not a second factor at
     * all. An admin can turn somebody else's off from the users screen — for
     * the phone that is genuinely lost — and that is the whole of their part.
     */
    Route::get('profile/authenticator', [AuthenticatorController::class, 'show'])
        ->name('authenticator.show');
    Route::post('profile/authenticator', [AuthenticatorController::class, 'confirm'])
        ->name('authenticator.confirm');
    Route::post('profile/authenticator/codes', [AuthenticatorController::class, 'regenerate'])
        ->name('authenticator.codes');
    Route::delete('profile/authenticator', [AuthenticatorController::class, 'destroy'])
        ->name('authenticator.destroy');

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
    /*
     * And the other way out of that list: the row itself, gone.
     *
     * Admin rather than products.delete, because everything else on this screen
     * can be undone from this screen and this cannot. It refuses outright if
     * anything at all still points at the product, and it takes a backup first.
     */
    Route::delete('products/{product}/purge', [ProductController::class, 'purge'])
        ->withTrashed()
        ->middleware('admin')->name('products.purge');
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

    // The lost phone. Same screen, same admin, and nothing an admin could not
    // already do — they can type this person a new password outright.
    Route::delete('users/{user}/authenticator', [UserController::class, 'clearAuthenticator'])
        ->middleware('admin')->name('users.authenticator.destroy');

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
    /*
     * ⚠️ **One counter for everything that comes back** — Soran, 2026-09-25.
     * The page issues SWP, SRT, PRT or the pair the answer needs, so it is
     * open to anybody holding ANY of those keys and each action carries its
     * own. `EnsurePermission` treats several keys as OR, which is exactly the
     * question here: may this reader do at least one of the three?
     */
    Route::get('goods-back', [GoodsBackController::class, 'index'])
        ->middleware('permission:swaps.create,sale_returns.create,purchase_returns.create')
        ->name('goods-back.index');
    Route::get('goods-back/suggest', [GoodsBackController::class, 'suggest'])
        ->middleware('permission:swaps.create,sale_returns.create,purchase_returns.create')
        ->name('goods-back.suggest');
    Route::post('goods-back/swap', [GoodsBackController::class, 'swap'])
        ->middleware('permission:swaps.create')->name('goods-back.swap');
    Route::post('goods-back/exchange', [GoodsBackController::class, 'exchange'])
        ->middleware('permission:sale_returns.create')->name('goods-back.exchange');
    Route::post('goods-back/refund', [GoodsBackController::class, 'refund'])
        ->middleware('permission:sale_returns.create')->name('goods-back.refund');
    Route::post('goods-back/send-back', [GoodsBackController::class, 'sendBack'])
        ->middleware('permission:purchase_returns.create')->name('goods-back.send-back');

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

    /*
     * ---- Swaps ----------------------------------------------------------
     * A faulty item handed back and replaced with the same thing. Its own
     * permission: a swap moves stock AND bills a supplier, which is more than
     * taking a return and more than selling.
     *
     * ⚠️ `swaps/create` is declared above `swaps/{swap}`, or the word "create"
     * is read as a swap id and the page 404s.
     */
    Route::get('swaps', [SwapController::class, 'index'])
        ->middleware('permission:swaps.view')->name('swaps.index');
    /*
     * ⚠️ **Swaps are started on `Goods coming back` now** — Soran,
     * 2026-09-25: *"remove page swaps because i use goods-back it"*. The old
     * screen is gone; this redirect stays so a bookmark, a printed link or a
     * page somebody left open does not land on a missing page.
     *
     * Deliberately unnamed: `route('swaps.create')` must fail loudly wherever
     * it is still called, rather than quietly sending a reader somewhere the
     * button did not promise.
     */
    Route::get('swaps/create', fn () => redirect()->route('goods-back.index'))
        ->middleware('permission:swaps.create');
    Route::get('swaps/{swap}', [SwapController::class, 'show'])
        ->middleware('permission:swaps.view')->name('swaps.show');
    // ⚠️ The note only. See SwapController::update for why nothing else is.
    Route::patch('swaps/{swap}', [SwapController::class, 'update'])
        ->middleware('permission:swaps.edit')->name('swaps.update');
    Route::delete('swaps/{swap}', [SwapController::class, 'destroy'])
        ->middleware('permission:swaps.delete')->name('swaps.destroy');

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
    // Section 8's reverse-and-re-apply, the same shape as every other document.
    Route::get('payments/{payment}/edit', [PaymentController::class, 'edit'])
        ->middleware('permission:payments.edit')->name('payments.edit');
    Route::put('payments/{payment}', [PaymentController::class, 'update'])
        ->middleware('permission:payments.edit')->name('payments.update');
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
    /*
     * Section 8's reverse-and-re-apply. Corrected in the same box it was
     * written in — an adjustment is six fields, and a screen of its own to
     * change one of them is a page that exists only to be left again.
     */
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

        // Who mended what, and what the shop made on it — Soran, 2026-09-22.
        Route::get('technicians', [ReportController::class, 'technicians'])
            ->middleware('permission:repairs.view')->name('technicians');
        /*
         * ⚠️ **The two sheets that answer "is this right?" rather than "how
         * much?"** — Soran, 2026-09-25. One shows where every dinar of profit
         * came from, down to the invoice line; the other replays the whole
         * history looking for a sale that took the wrong FIFO layer, which is
         * the one fault every other screen in the shop agrees with.
         */
        Route::get('profit', [ReportController::class, 'whereProfitCameFrom'])->name('profit');
        Route::get('fifo', [ReportController::class, 'fifo'])->name('fifo');

        Route::get('receivable', [ReportController::class, 'receivable'])->name('receivable');
        Route::get('payable', [ReportController::class, 'payable'])->name('payable');
    });

    // ---- System ----------------------------------------------------------
    /*
     * The guide. No permission on either route, deliberately — the reader most
     * likely to need it is the newest assistant, holding the fewest
     * permissions in the shop. It reads none of the shop's data.
     */
    Route::get('guide', [GuideController::class, 'index'])->name('guide.index');
    Route::get('guide/{topic}', [GuideController::class, 'show'])->name('guide.show');

    /*
     * Stock rooms — Soran, 2026-09-15.
     *
     * ⚠️ Three permissions, not one. Seeing which room holds what is something
     * a counter assistant needs to answer "have you got one out the back".
     * Moving goods, and adding or closing a room, change where the shop's stock
     * is — a shop should be able to say who may do that.
     */
    Route::get('stock-rooms', [StockRoomController::class, 'index'])
        ->middleware('permission:stock_rooms.view')->name('stock-rooms.index');
    Route::get('stock-rooms/{stockRoom}', [StockRoomController::class, 'show'])
        ->middleware('permission:stock_rooms.view')->name('stock-rooms.show');
    Route::post('stock-rooms', [StockRoomController::class, 'store'])
        ->middleware('permission:stock_rooms.manage')->name('stock-rooms.store');
    Route::put('stock-rooms/{stockRoom}', [StockRoomController::class, 'update'])
        ->middleware('permission:stock_rooms.manage')->name('stock-rooms.update');
    Route::delete('stock-rooms/{stockRoom}', [StockRoomController::class, 'destroy'])
        ->middleware('permission:stock_rooms.manage')->name('stock-rooms.destroy');

    /*
     * The workshop book — Soran, 2026-09-20. Nothing here moves stock or money
     * except `collect`, which does it by making an ordinary sale.
     */
    Route::get('repairs', [RepairController::class, 'index'])
        ->middleware('permission:repairs.view')->name('repairs.index');
    Route::get('repairs/create', [RepairController::class, 'create'])
        ->middleware('permission:repairs.create')->name('repairs.create');
    /*
     * Has this device been here before, and is it still under warranty —
     * Soran, 2026-09-23. Read-only, and it answers only what the repairs list
     * would answer to the same reader searching the same number by hand.
     *
     * ⚠️ ABOVE `repairs/{repair}`, like `repairs/create` above it. Routes match
     * in the order they are declared, so a literal path declared after the
     * wildcard is never reached — "history" would be read as a repair id and
     * answer 404.
     */
    Route::get('repairs/history', [RepairController::class, 'history'])
        ->middleware('permission:repairs.view')->name('repairs.history');

    Route::post('repairs', [RepairController::class, 'store'])
        ->middleware('permission:repairs.create')->name('repairs.store');
    Route::get('repairs/{repair}', [RepairController::class, 'show'])
        ->middleware('permission:repairs.view')->name('repairs.show');
    Route::get('repairs/{repair}/edit', [RepairController::class, 'edit'])
        ->middleware('permission:repairs.edit')->name('repairs.edit');
    Route::put('repairs/{repair}', [RepairController::class, 'update'])
        ->middleware('permission:repairs.edit')->name('repairs.update');
    Route::post('repairs/{repair}/accept', [RepairController::class, 'accept'])
        ->middleware('permission:repairs.edit')->name('repairs.accept');
    Route::patch('repairs/{repair}/status', [RepairController::class, 'status'])
        ->middleware('permission:repairs.edit')->name('repairs.status');
    Route::patch('repairs/{repair}/hand-back', [RepairController::class, 'handBack'])
        ->middleware('permission:repairs.edit')->name('repairs.hand-back');
    /* ⚠️ Collecting creates a sale, so it takes the sale permission too. */
    Route::post('repairs/{repair}/collect', [RepairController::class, 'collect'])
        ->middleware('permission:repairs.edit', 'permission:sales.create')->name('repairs.collect');
    Route::delete('repairs/{repair}', [RepairController::class, 'destroy'])
        ->middleware('permission:repairs.delete')->name('repairs.destroy');
    Route::get('repairs/{repair}/ticket', [RepairController::class, 'ticket'])
        ->middleware('permission:repairs.view')->name('repairs.ticket');

    /*
     * A part the shop has not got, bought for the job without leaving this
     * screen — Soran, 2026-09-23. ⚠️ Its own permission: spending the shop's
     * money is not the same power as working on a repair.
     */
    Route::post('repairs/parts', [RepairController::class, 'buyPart'])
        ->middleware('permission:repairs.buy_part')->name('repairs.buy-part');

    /*
     * ---- Taking apart and building ---------------------------------------
     * ⚠️ `assemblies/create` above `assemblies/{assembly}`, or the word
     * "create" is read as a document id and the page 404s.
     */
    Route::get('assemblies', [AssemblyController::class, 'index'])
        ->middleware('permission:assemblies.view')->name('assemblies.index');
    Route::get('assemblies/create', [AssemblyController::class, 'create'])
        ->middleware('permission:assemblies.create')->name('assemblies.create');
    Route::post('assemblies', [AssemblyController::class, 'store'])
        ->middleware('permission:assemblies.create')->name('assemblies.store');
    Route::post('assemblies/share', [AssemblyController::class, 'share'])
        ->middleware('permission:assemblies.create')->name('assemblies.share');
    Route::get('assemblies/{assembly}', [AssemblyController::class, 'show'])
        ->middleware('permission:assemblies.view')->name('assemblies.show');
    Route::get('assemblies/{assembly}/edit', [AssemblyController::class, 'edit'])
        ->middleware('permission:assemblies.create')->name('assemblies.edit');
    Route::put('assemblies/{assembly}', [AssemblyController::class, 'update'])
        ->middleware('permission:assemblies.create')->name('assemblies.update');
    Route::delete('assemblies/{assembly}', [AssemblyController::class, 'destroy'])
        ->middleware('permission:assemblies.delete')->name('assemblies.destroy');

    Route::get('stock-transfers', [StockTransferController::class, 'index'])
        ->middleware('permission:stock_rooms.view')->name('stock-transfers.index');
    Route::get('stock-transfers/create', [StockTransferController::class, 'create'])
        ->middleware('permission:stock_rooms.transfer')->name('stock-transfers.create');
    Route::get('stock-transfers/room/{stockRoom}', [StockTransferController::class, 'stock'])
        ->middleware('permission:stock_rooms.transfer')->name('stock-transfers.stock');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])
        ->middleware('permission:stock_rooms.transfer')->name('stock-transfers.store');
    Route::get('stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])
        ->middleware('permission:stock_rooms.view')->name('stock-transfers.show');
    Route::delete('stock-transfers/{stockTransfer}', [StockTransferController::class, 'destroy'])
        ->middleware('permission:stock_rooms.transfer')->name('stock-transfers.destroy');

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

        /*
         * "Does the data still agree with itself?" — Section 10b's global
         * assertions run against the real shop rather than against a test.
         *
         * Read-only, so it sits behind settings.manage rather than admin: it
         * shows nothing a settings-holder cannot already see, and the person
         * who would think to run it is the person who keeps the books.
         */
        Route::get('settings/data-check', [DataCheckController::class, 'index'])->name('settings.data-check');

        /*
         * Section 2b — the currencies a shop can type and read in.
         *
         * Its own page rather than a card on the settings form: each currency
         * is a row with five fields, and a list of records is not something a
         * single form of scalars can hold. Behind settings.manage with the
         * rest, because a wrong rate misprices every line typed after it.
         */
        Route::get('settings/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        Route::post('settings/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::put('settings/currencies/{currency}', [CurrencyController::class, 'update'])
            ->name('currencies.update');

        /*
         * ⚠️ Moving the books to another currency REINTERPRETS every stored
         * figure — see CurrencyController. Allowed only while nothing has been
         * recorded, which the controller checks rather than the route.
         */
        Route::post('settings/currencies/{currency}/base', [CurrencyController::class, 'base'])
            ->name('currencies.base');

        Route::delete('settings/currencies/{currency}', [CurrencyController::class, 'destroy'])
            ->name('currencies.destroy');
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
