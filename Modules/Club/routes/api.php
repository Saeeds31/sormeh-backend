<?php

use Illuminate\Support\Facades\Route;
use Modules\Club\Http\Controllers\ClubSettingController;
use Modules\Club\Http\Controllers\ClubController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('clubs', ClubController::class)->names('club');
});

Route::prefix('v1/admin/club')->middleware(['auth:sanctum'])->group(function () {
    Route::get('users', [ClubController::class, 'users']);
    Route::get('users/{user}', [ClubController::class, 'userDetail']);
    Route::post('users/{user}/points/add', [ClubController::class, 'addPoints']);
    Route::post('users/{user}/points/deduct', [ClubController::class, 'deductPoints']);
    Route::get('users/{user}/usages', [ClubController::class, 'usages']);
    Route::get('settings/status',  [ClubSettingController::class, 'status']);
    Route::post('settings/ensure', [ClubSettingController::class, 'ensure']);
    Route::get('settings',                       [ClubSettingController::class, 'index']);
    Route::put('settings',                       [ClubSettingController::class, 'update']);
    Route::get('settings/status',                [ClubSettingController::class, 'status']);
    Route::post('settings/ensure',               [ClubSettingController::class, 'ensure']);
});
Route::prefix('v1/front/club')->middleware('auth:sanctum')->group(function () {
    Route::get('summary', [ClubController::class, 'summary']);
    Route::get('transactions', [ClubController::class, 'transactions']);
    Route::get('usagesFront', [ClubController::class, 'usages']);
    Route::get('can-free-shipping', [ClubController::class, 'canUseFreeShipping']);
});
