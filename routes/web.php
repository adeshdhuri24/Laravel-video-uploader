<?php

use App\Http\Controllers\V1\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

// Alias for Breeze tests and any layout that still reference route('dashboard')
Route::middleware(['auth'])->get('/dashboard', fn () => redirect()->route('admin.dashboard'))->name('dashboard');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('dashboard');
    Route::get('/upload', [AdminController::class, 'upload'])->name('upload');
    Route::get('/{uploadId}', [AdminController::class, 'show'])->name('show');
});

require __DIR__.'/auth.php';
