<?php

use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\DraftShipmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CustomerPortalController::class, 'index'])->name('home');
Route::post('/uoc-tinh-cuoc', [CustomerPortalController::class, 'estimate'])
    ->middleware('throttle:30,1')->name('shipping.estimate');
Route::post('/tra-cuu', [CustomerPortalController::class, 'track'])
    ->middleware('throttle:10,1')->name('shipping.track');

Route::get('/gui-hang', [DraftShipmentController::class, 'create'])->name('drafts.create');
Route::get('/don-hang', [DraftShipmentController::class, 'index'])->name('drafts.index');
Route::post('/don-hang', [DraftShipmentController::class, 'store'])
    ->middleware('throttle:20,1')->name('drafts.store');
Route::get('/don-hang/{id}/sua', [DraftShipmentController::class, 'edit'])->whereUuid('id')->name('drafts.edit');
Route::put('/don-hang/{id}', [DraftShipmentController::class, 'update'])->whereUuid('id')->name('drafts.update');
Route::delete('/don-hang/{id}', [DraftShipmentController::class, 'destroy'])->whereUuid('id')->name('drafts.destroy');
