<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('bao-cao')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'home'])->name('home');
    Route::get('/tep-xuat', [ReportController::class, 'history'])->name('history');
    Route::get('/tep/{export}', [ReportController::class, 'export'])->name('export');
    Route::get('/tep/{export}/tai', [ReportController::class, 'download'])->middleware('signed')->name('download');
    Route::get('/{type}', [ReportController::class, 'index'])->whereIn('type', ['operations', 'finance'])->name('index');
    Route::post('/{type}/xuat', [ReportController::class, 'store'])->whereIn('type', ['operations', 'finance'])
        ->middleware('throttle:10,1')->name('store');
});
