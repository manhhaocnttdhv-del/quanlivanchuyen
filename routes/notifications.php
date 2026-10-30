<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('thong-bao')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/so-chua-doc', [NotificationController::class, 'count'])->middleware('throttle:60,1')->name('count');
    Route::post('/doc-tat-ca', [NotificationController::class, 'readAll'])->name('read-all');
    Route::get('/{notification}/mo', [NotificationController::class, 'open'])->name('open');
    Route::patch('/{notification}', [NotificationController::class, 'update'])->name('update');
});
