<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\SchoolController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/**
 * Public liveness probe used by the SPA to confirm API wiring.
 * Returns no business data.
 */
Route::get('/ping', function () {
    return [
        'ok' => true,
        'app' => config('app.name'),
        'laravel' => Application::VERSION,
        'time' => now()->toIso8601String(),
    ];
});

/*
 * Admin / coordinator authentication (Sanctum cookie session for the SPA).
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('user', [AuthController::class, 'user'])->middleware('auth:sanctum');
});

/*
 * Authenticated staff API. Object-level authorization is enforced per endpoint
 * via policies; listing is scoped server-side.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('countries', [CountryController::class, 'index']);
    Route::apiResource('schools', SchoolController::class);
});
