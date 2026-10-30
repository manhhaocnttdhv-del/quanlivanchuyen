<?php

use App\Http\Controllers\CashDepositController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinanceEntryController;
use App\Http\Controllers\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('tai-chinh')->name('finance.')->group(function () {
    Route::get('/{section?}', [FinanceController::class, 'index'])->where('section', 'collections|deposits|reconciliations|fees|receipts|adjustments')->name('index');
    Route::get('/khoan-thu/{collection}', [FinanceController::class, 'collection'])->name('collections.show');
    Route::get('/khoan-phi/{fee}', [FinanceController::class, 'fee'])->name('fees.show');
    Route::get('/chung-tu/{attachment}', [FinanceController::class, 'attachment'])->name('attachment');
    Route::get('/nop-tien/them', [CashDepositController::class, 'create'])->name('deposits.create');
    Route::post('/nop-tien', [CashDepositController::class, 'store'])->name('deposits.store');
    Route::get('/nop-tien/{deposit}', [CashDepositController::class, 'show'])->name('deposits.show');
    Route::post('/nop-tien/{deposit}/{operation}', [CashDepositController::class, 'action'])->where('operation', 'confirm|allocate|reject')->name('deposits.action');
    Route::get('/doi-soat/them', [ReconciliationController::class, 'create'])->name('reconciliations.create');
    Route::post('/doi-soat', [ReconciliationController::class, 'store'])->name('reconciliations.store');
    Route::get('/doi-soat/{reconciliation}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
    Route::get('/doi-soat/{reconciliation}/sua', [ReconciliationController::class, 'edit'])->name('reconciliations.edit');
    Route::post('/doi-soat/{reconciliation}/thanh-toan', [ReconciliationController::class, 'payment'])->name('payments.store');
    Route::post('/doi-soat/{reconciliation}/{operation}', [ReconciliationController::class, 'action'])->where('operation', 'replace|submit|approve|return|reject|cancel|reopen|refresh-bank')->name('reconciliations.action');
    Route::get('/thu-phi/them', [FinanceEntryController::class, 'receiptForm'])->name('receipts.create');
    Route::post('/thu-phi', [FinanceEntryController::class, 'receiptStore'])->name('receipts.store');
    Route::get('/thu-phi/{receipt}', [FinanceEntryController::class, 'receipt'])->name('receipts.show');
    Route::get('/dieu-chinh/them', [FinanceEntryController::class, 'adjustmentForm'])->name('adjustments.create');
    Route::post('/don/{order}/dieu-chinh', [FinanceEntryController::class, 'adjustmentStore'])->name('adjustments.store');
    Route::get('/dieu-chinh/{adjustment}', [FinanceEntryController::class, 'adjustment'])->name('adjustments.show');
    Route::post('/dieu-chinh/{adjustment}/{operation}', [FinanceEntryController::class, 'adjustmentReview'])->where('operation', 'approve|reject')->name('adjustments.action');
});
