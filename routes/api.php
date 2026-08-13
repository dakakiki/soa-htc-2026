<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
