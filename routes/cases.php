<?php

use App\Http\Controllers\CaseAttachmentController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\IncidentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/su-co', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/su-co/them', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/don/{order}/su-co', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/su-co/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/su-co/{incident}/{operation}', [IncidentController::class, 'action'])->where('operation', 'note|assign|hold|propose|review')->name('incidents.action');
    Route::get('/khieu-nai', [ComplaintController::class, 'index'])->name('complaints.index');
    Route::get('/khieu-nai/them', [ComplaintController::class, 'create'])->name('complaints.create');
    Route::post('/don/{order}/khieu-nai', [ComplaintController::class, 'store'])->name('complaints.store');
    Route::get('/khieu-nai/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
    Route::post('/khieu-nai/{complaint}/{operation}', [ComplaintController::class, 'action'])->where('operation', 'message|assign|link|close|reopen')->name('complaints.action');
    Route::get('/tep-ho-so/{attachment}', [CaseAttachmentController::class, 'show'])->name('cases.attachment');
});
