<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AccountAdjustmentController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountTransactionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\OwnerEntryController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProformaController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\ShopLogoController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // The web app logs in with a session cookie instead of a token.
    Route::post('auth/session', [AuthController::class, 'startSession'])
        ->middleware('throttle:login')
        ->name('auth.session.store');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::delete('auth/session', [AuthController::class, 'endSession'])->name('auth.session.destroy');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('shop', [ShopController::class, 'show'])->name('shop.show');
        Route::middleware('permission:'.Permission::SettingsManage->value)->group(function () {
            Route::patch('shop', [ShopController::class, 'update'])->name('shop.update');
            Route::post('shop/logo', [ShopLogoController::class, 'store'])->name('shop.logo.store');
            Route::delete('shop/logo', [ShopLogoController::class, 'destroy'])->name('shop.logo.destroy');
        });
        Route::post('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::middleware('permission:'.Permission::UsersManage->value)->group(function () {
            Route::apiResource('users', UserController::class)->except(['destroy']);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
            Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
            Route::post('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::apiResource('roles', RoleController::class);
        });

        // Master data: any logged-in user can read, writes need the matching permission.
        Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::get('price-list', PriceListController::class)->name('price-list');
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
        Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);

        Route::middleware('permission:'.Permission::ProductsManage->value)->group(function () {
            Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
            Route::apiResource('products', ProductController::class)->except(['index', 'show']);
            Route::post('products/{product}/image', [ProductImageController::class, 'store'])->name('products.image.store');
            Route::delete('products/{product}/image', [ProductImageController::class, 'destroy'])->name('products.image.destroy');
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
            Route::post('purchases/preview', [PurchaseController::class, 'preview'])->name('purchases.preview');
            Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store', 'show']);
            Route::patch('purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
        });
        Route::post('purchases/{purchase}/void', [PurchaseController::class, 'void'])
            ->middleware('permission:'.Permission::PurchasesVoid->value)
            ->name('purchases.void');

        // Sales: anyone logged in can view them (costs are hidden without reports.view).
        Route::apiResource('sales', SaleController::class)->only(['index', 'show']);
        Route::post('sales/preview', [SaleController::class, 'preview'])
            ->middleware('permission:'.Permission::SalesCreate->value)
            ->name('sales.preview');
        Route::post('sales', [SaleController::class, 'store'])
            ->middleware('permission:'.Permission::SalesCreate->value)
            ->name('sales.store');
        Route::patch('sales/{sale}', [SaleController::class, 'update'])
            ->middleware('permission:'.Permission::SalesCreate->value)
            ->name('sales.update');
        Route::post('sales/{sale}/void', [SaleController::class, 'void'])
            ->middleware('permission:'.Permission::SalesVoid->value)
            ->name('sales.void');

        // Proformas: anyone logged in can view them; quoting, editing, cancelling and converting need sales.create.
        Route::apiResource('proformas', ProformaController::class)->only(['index', 'show']);
        Route::middleware('permission:'.Permission::SalesCreate->value)->group(function () {
            Route::post('proformas/preview', [ProformaController::class, 'preview'])->name('proformas.preview');
            Route::post('proformas', [ProformaController::class, 'store'])->name('proformas.store');
            Route::patch('proformas/{proforma}', [ProformaController::class, 'update'])->name('proformas.update');
            Route::post('proformas/{proforma}/cancel', [ProformaController::class, 'cancel'])->name('proformas.cancel');
            Route::post('proformas/{proforma}/convert', [ProformaController::class, 'convert'])->name('proformas.convert');
        });

        // Later payments against a purchase or sale (purchases also need purchases.create).
        Route::post('payments', PaymentController::class)
            ->middleware('permission:'.Permission::PaymentsRecord->value)
            ->name('payments.store');

        Route::post('expenses', ExpenseController::class)
            ->middleware('permission:'.Permission::ExpensesRecord->value)
            ->name('expenses.store');

        Route::middleware('permission:'.Permission::AccountsManage->value)->group(function () {
            Route::post('transfers', TransferController::class)->name('transfers.store');
            Route::post('owner-entries', OwnerEntryController::class)->name('owner-entries.store');
            Route::post('account-adjustments/preview', [AccountAdjustmentController::class, 'preview'])->name('account-adjustments.preview');
            Route::post('account-adjustments', AccountAdjustmentController::class)->name('account-adjustments.store');
        });

        // Reversing a payment needs payments.void, any other entry accounts.manage (checked in the request).
        Route::post('account-transactions/{accountTransaction}/reverse', [AccountTransactionController::class, 'reverse'])
            ->name('account-transactions.reverse');

        // The ledger: for reports, and for users who record money and may need to find an entry to reverse.
        Route::get('account-transactions', [AccountTransactionController::class, 'index'])
            ->middleware('permission:'.Permission::ReportsView->value.'|'.Permission::AccountsManage->value)
            ->name('account-transactions.index');

        // Reports.
        Route::middleware('permission:'.Permission::ReportsView->value)->group(function () {
            Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
            Route::get('reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
            Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
        });
    });
});
