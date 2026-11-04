<?php

use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\StocktakeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/dieu-phoi', [LogisticsController::class, 'index'])->name('logistics.index');
    Route::get('/van-hanh/don/{order}', [LogisticsController::class, 'order'])->name('logistics.order');
    Route::post('/van-hanh/don/{order}/phan-cong', [LogisticsController::class, 'assign'])->name('logistics.assign');
    Route::post('/van-hanh/don/{order}/quyet-dinh-hoan', [LogisticsController::class, 'authorizeReturn'])->name('logistics.return');
    Route::get('/nhiem-vu/{task}', [LogisticsController::class, 'show'])->name('logistics.show');
    Route::post('/nhiem-vu/{task}/lay-hang', [LogisticsController::class, 'pickup'])->name('logistics.pickup');
    Route::post('/nhiem-vu/{task}/thu-hoi', [LogisticsController::class, 'recall'])->name('logistics.recall');
    Route::post('/nhiem-vu/{task}/ban-giao', [LogisticsController::class, 'prepare'])->name('logistics.prepare');
    Route::get('/ban-giao/{handover}', [LogisticsController::class, 'handover'])->name('logistics.handover');
    Route::post('/ban-giao/{handover}/xac-nhan', [LogisticsController::class, 'confirm'])->name('logistics.confirm');
    Route::get('/ton-kho', [LogisticsController::class, 'inventory'])->name('logistics.inventory');
    Route::post('/ton-kho/vi-tri', [LogisticsController::class, 'location'])->name('logistics.location');
    Route::post('/sai-lech/{discrepancy}/xu-ly', [LogisticsController::class, 'resolve'])->name('logistics.resolve');
    Route::post('/sai-lech/{discrepancy}/de-nghi-can', [LogisticsController::class, 'weightReview'])->name('logistics.weight-review');
    Route::get('/bang-chung/{kind}/{id}', [LogisticsController::class, 'evidence'])->where('kind', 'pickup|handover|discrepancy')->whereNumber('id')->name('logistics.evidence');
    Route::get('/kiem-ke', [StocktakeController::class, 'index'])->name('stocktakes.index');
    Route::post('/kiem-ke', [StocktakeController::class, 'store'])->name('stocktakes.store');
    Route::get('/kiem-ke/{stocktake}', [StocktakeController::class, 'show'])->name('stocktakes.show');
    Route::post('/kiem-ke/{stocktake}/gui', [StocktakeController::class, 'submit'])->name('stocktakes.submit');
    Route::post('/kiem-ke/{stocktake}/duyet', [StocktakeController::class, 'approve'])->name('stocktakes.approve');
});
