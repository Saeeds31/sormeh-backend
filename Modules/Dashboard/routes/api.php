<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;
use Modules\Dashboard\Http\Controllers\ImageController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
});
Route::prefix('v1/admin/article-lib')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/images', [ImageController::class, 'index']);           // لیست تصاویر
    Route::post('/images', [ImageController::class, 'store']);          // آپلود تصویر
    Route::delete('/images/{filename}', [ImageController::class, 'destroy']); // حذف تصویر
});
