<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth'])->group(function () {
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

    // Section 9: Users — admin only.
    Route::resource('users', UserController::class)
        ->except(['show'])
        ->middleware('permission:users.view');

    // ---- Sell & buy ------------------------------------------------------
    Route::get('sales', [SaleController::class, 'index'])
        ->middleware('permission:sales.view')->name('sales.index');
    Route::get('sales/create', [SaleController::class, 'create'])
        ->middleware('permission:sales.create')->name('sales.create');
    Route::post('sales', [SaleController::class, 'store'])
        ->middleware('permission:sales.create')->name('sales.store');
    Route::get('sales/{sale}', [SaleController::class, 'show'])
        ->middleware('permission:sales.view')->name('sales.show');

    Route::get('purchases', [PurchaseController::class, 'index'])
        ->middleware('permission:purchases.view')->name('purchases.index');
    Route::get('purchases/create', [PurchaseController::class, 'create'])
        ->middleware('permission:purchases.create')->name('purchases.create');
    Route::post('purchases', [PurchaseController::class, 'store'])
        ->middleware('permission:purchases.create')->name('purchases.store');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])
        ->middleware('permission:purchases.view')->name('purchases.show');

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

    // ---- Money -----------------------------------------------------------
    Route::get('payments', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view')->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])
        ->middleware('permission:payments.create')->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.create')->name('payments.store');

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
