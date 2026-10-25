<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
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

        Route::middleware('permission:'.Permission::ProductsManage->value)->group(function () {
            Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
        });
    });
});
