<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeeVersionController;
use App\Http\Controllers\InternalUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ShopReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/dang-nhap', [AuthController::class, 'login'])->name('login');
    Route::post('/dang-nhap', [AuthController::class, 'authenticate'])->middleware('throttle:20,1')->name('login.store');
    Route::get('/dang-ky', [AuthController::class, 'register'])->name('register');
    Route::post('/dang-ky', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});
Route::middleware('auth')->group(function () {
    Route::post('/dang-xuat', [AuthController::class, 'logout'])->name('logout');
    Route::get('/tong-quan', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/ho-so', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/ho-so', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/mat-khau', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/cua-hang', [ShopController::class, 'profile'])->name('shop.profile');
    Route::put('/cua-hang', [ShopController::class, 'update'])->name('shop.update');
    Route::post('/cua-hang/ngan-hang', [ShopController::class, 'bank'])->name('shop.bank');
    Route::get('/dia-chi/them', [ShopController::class, 'address'])->name('pickups.create');
    Route::get('/dia-chi/{pickup}/sua', [ShopController::class, 'address'])->name('pickups.edit');
    Route::post('/dia-chi', [ShopController::class, 'saveAddress'])->name('pickups.store');
    Route::put('/dia-chi/{pickup}', [ShopController::class, 'saveAddress'])->name('pickups.update');

    Route::prefix('quan-tri')->group(function () {
        Route::middleware('can:users.manage')->group(function () {
            Route::resource('tai-khoan', InternalUserController::class)->parameters(['tai-khoan' => 'user'])->names('users')->except(['show', 'destroy']);
        });
        Route::get('/shops', [ShopReviewController::class, 'index'])->middleware('can:any-shop-review')->name('shops.index');
        Route::get('/shops/{shop}', [ShopReviewController::class, 'show'])->name('shops.show');
        Route::post('/shops/{shop}/review', [ShopReviewController::class, 'review'])->name('shops.review');
        Route::post('/banks/{bank}/verify', [ShopReviewController::class, 'verify'])->name('banks.verify');
        Route::middleware('can:catalog.manage')->group(function () {
            Route::get('/danh-muc', [CatalogController::class, 'index'])->name('catalog.index');
            Route::get('/khu-vuc/them', [CatalogController::class, 'area'])->name('areas.create');
            Route::get('/khu-vuc/{area}/sua', [CatalogController::class, 'area'])->name('areas.edit');
            Route::post('/khu-vuc', [CatalogController::class, 'saveArea'])->name('areas.store');
            Route::put('/khu-vuc/{area}', [CatalogController::class, 'saveArea'])->name('areas.update');
            Route::get('/kho/them', [CatalogController::class, 'warehouse'])->name('warehouses.create');
            Route::get('/kho/{warehouse}/sua', [CatalogController::class, 'warehouse'])->name('warehouses.edit');
            Route::post('/kho', [CatalogController::class, 'saveWarehouse'])->name('warehouses.store');
            Route::put('/kho/{warehouse}', [CatalogController::class, 'saveWarehouse'])->name('warehouses.update');
            Route::get('/bieu-phi', [FeeVersionController::class, 'index'])->name('fees.index');
            Route::get('/bieu-phi/them', [FeeVersionController::class, 'form'])->name('fees.create');
            Route::get('/bieu-phi/{version}/sua', [FeeVersionController::class, 'form'])->name('fees.edit');
            Route::post('/bieu-phi', [FeeVersionController::class, 'save'])->name('fees.store');
            Route::put('/bieu-phi/{version}', [FeeVersionController::class, 'save'])->name('fees.update');
            Route::post('/bieu-phi/{version}/cong-bo', [FeeVersionController::class, 'publish'])->name('fees.publish');
        });
        Route::get('/nhat-ky', [AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
    });
});
