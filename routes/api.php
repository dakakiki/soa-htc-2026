<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\UserController;
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
    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('countries', [CountryController::class, 'index']);
    Route::get('regions', [RegionController::class, 'index']);
    Route::get('permissions', [PermissionController::class, 'index']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::apiResource('schools', SchoolController::class);

    // Staff users and their season role/scope assignments.
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'store', 'update']);
    Route::post('users/{user}/assignments', [AssignmentController::class, 'store']);
    Route::put('assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);
});
