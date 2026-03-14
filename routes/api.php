<?php

use App\Http\Controllers\V1\AdminApiController;
use App\Http\Controllers\V1\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {

    Route::middleware('auth:sanctum')->group(function () {

        // ── Chunked upload flow ──────────────────────────────────────────────
        Route::prefix('upload')->name('upload.')->group(function () {
            Route::post('/init', [UploadController::class, 'init'])->name('init');
            Route::post('/chunk', [UploadController::class, 'storeChunk'])->name('chunk');
            Route::post('/finalize', [UploadController::class, 'finalize'])->name('finalize');
            Route::get('/{uploadId}/status', [UploadController::class, 'status'])->name('status');
            Route::delete('/{uploadId}', [UploadController::class, 'cancel'])->name('cancel');
            Route::post('/{uploadId}/abandon', [UploadController::class, 'abandon'])->name('abandon');
        });

        // ── Admin data endpoints ───────────
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/uploads', [AdminApiController::class, 'uploads'])->name('uploads');
            Route::get('/uploads/{uploadId}', [AdminApiController::class, 'uploadDetail'])->name('upload.detail');
            Route::get('/{uploadId}/download', [AdminApiController::class, 'download'])->name('download');
        });

    });

});
