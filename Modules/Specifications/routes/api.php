<?php

use Illuminate\Support\Facades\Route;
use Modules\Specifications\Http\Controllers\SpecificationsController;
use Modules\Specifications\Http\Controllers\SpecificationValueController;

/*
|--------------------------------------------------------------------------
| Admin Routes (مدیریت) - نیازمند احراز هویت
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {

    // ===== Specifications CRUD =====
    Route::prefix('specifications')->group(function () {
        Route::get('/', [SpecificationsController::class, 'index']);       // لیست همه مشخصات
        Route::post('/', [SpecificationsController::class, 'store']);      // ایجاد مشخصه
        Route::get('/{id}', [SpecificationsController::class, 'show']);    // نمایش مشخصه با مقادیرش
        Route::put('/{id}', [SpecificationsController::class, 'update']);  // ویرایش مشخصه
        Route::delete('/{id}', [SpecificationsController::class, 'destroy']); // حذف مشخصه
    });

    Route::get('/all-specification', [SpecificationsController::class, 'allSpecifications'])
        ->name('allSpecifications');

    Route::post('/sync-specification/{productId}', [SpecificationsController::class, 'syncSpecifications'])
        ->name('syncSpecifications');

    // ===== Specification Values CRUD =====
    Route::prefix('specification-values')->group(function () {
        Route::post('/', [SpecificationValueController::class, 'store']);       // افزودن مقدار جدید
        Route::put('/{id}', [SpecificationValueController::class, 'update']);   // ویرایش مقدار
        Route::delete('/{id}', [SpecificationValueController::class, 'destroy']); // حذف مقدار
    });
});

/*
|--------------------------------------------------------------------------
| Public / User Routes (سمت کاربر و فرانت Nuxt)
|--------------------------------------------------------------------------
*/

// ---- مسیر عمومی: مشخصات سیستمی (نیازی به لاگین ندارد) ----
Route::prefix('v1/specifications')->group(function () {
    // ۱) برگرداندن مشخصات سیستمی به همراه مقادیر
    Route::get('/system', [SpecificationsController::class, 'systemSpecifications'])
        ->name('specifications.system');
});

// ---- مسیرهای کاربری: نیازمند احراز هویت ----
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::prefix('specifications')->group(function () {
        // ۲) ذخیره انتخاب‌های کاربر
        Route::post('/user-selections', [SpecificationsController::class, 'storeUserSelections'])
            ->name('specifications.userSelections.store');

        // ۳) پیشنهاد محصول بر اساس انتخاب‌های کاربر
        Route::get('/recommendations', [SpecificationsController::class, 'recommendations'])
            ->name('specifications.recommendations');
    });
});
