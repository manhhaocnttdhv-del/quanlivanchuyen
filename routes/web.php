<?php

use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CustomerPortalController::class, 'index'])->name('home');
Route::post('/uoc-tinh-cuoc', [CustomerPortalController::class, 'estimate'])
    ->middleware('throttle:30,1')->name('shipping.estimate');
Route::post('/tra-cuu', [CustomerPortalController::class, 'track'])
    ->middleware('throttle:10,1')->name('shipping.track');

Route::middleware('auth')->group(function () {
    Route::get('/gui-hang', [OrderController::class, 'create'])->name('orders.create');
    Route::get('/don-hang', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/don-hang/bang-ke', [OrderController::class, 'manifest'])->name('orders.manifest');
    Route::post('/don-hang', [OrderController::class, 'store'])->middleware('throttle:30,1')->name('orders.store');
    Route::get('/don-hang/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/don-hang/{order}/sua', [OrderController::class, 'edit'])->name('orders.edit');
    Route::put('/don-hang/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('/don-hang/{order}/gui', [OrderController::class, 'submit'])->name('orders.submit');
    Route::post('/don-hang/{order}/duyet', [OrderController::class, 'review'])->name('orders.review');
    Route::post('/don-hang/{order}/huy', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/don-hang/{order}/de-nghi-sua', [OrderController::class, 'change'])->name('orders.change');
    Route::post('/don-hang/{order}/de-nghi-huy', [OrderController::class, 'requestCancel'])->name('orders.request-cancel');
    Route::post('/de-nghi/{change}/duyet', [OrderController::class, 'reviewChange'])->name('orders.change-review');
    Route::get('/don-hang/{order}/tem', [OrderController::class, 'label'])->name('orders.label');
});

require __DIR__.'/portal.php';
require __DIR__.'/logistics.php';
require __DIR__.'/cases.php';
require __DIR__.'/finance.php';
