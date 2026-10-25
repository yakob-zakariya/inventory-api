<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountTransactionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::middleware('permission:'.Permission::UsersManage->value)->group(function () {
            Route::apiResource('users', UserController::class)->only(['index', 'store']);
        });

        // Master data: any logged-in user can read, writes need the matching permission.
        Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
        Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);

        Route::middleware('permission:'.Permission::ProductsManage->value)->group(function () {
            Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
            Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        });

        Route::middleware('permission:'.Permission::SuppliersManage->value)->group(function () {
            Route::apiResource('suppliers', SupplierController::class)->except(['index', 'show']);
        });

        Route::middleware('permission:'.Permission::CustomersManage->value)->group(function () {
            Route::apiResource('customers', CustomerController::class)->except(['index', 'show']);
        });

        Route::middleware('permission:'.Permission::AccountsManage->value)->group(function () {
            Route::apiResource('accounts', AccountController::class)->only(['store', 'update']);
        });

        // Stock: anyone logged in can read the ledger; adjustments need stock.adjust.
        Route::get('stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
        Route::post('stock-adjustments', StockAdjustmentController::class)
            ->middleware('permission:'.Permission::StockAdjust->value)
            ->name('stock-adjustments.store');

        // Purchases: viewing them shows supplier costs, so it needs purchases.create too.
        Route::middleware('permission:'.Permission::PurchasesCreate->value)->group(function () {
            Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store', 'show']);
        });

        // Sales: anyone logged in can view them (costs are hidden without reports.view).
        Route::apiResource('sales', SaleController::class)->only(['index', 'show']);
        Route::post('sales', [SaleController::class, 'store'])
            ->middleware('permission:'.Permission::SalesCreate->value)
            ->name('sales.store');

        // Money ledger and reports.
        Route::middleware('permission:'.Permission::ReportsView->value)->group(function () {
            Route::get('account-transactions', [AccountTransactionController::class, 'index'])->name('account-transactions.index');
        });
    });
});
