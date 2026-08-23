<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/**
 * The Vue SPA owns client-side routing. Every non-API, non-health path returns
 * the same shell so browser history / deep links resolve inside Vue Router —
 * but the shell is rendered by {@see SpaController}, which fills in the title
 * and social tags for public content and answers a renamed slug with a 301.
 */
Route::get('/', SpaController::class);
Route::get('/{any}', SpaController::class)->where('any', '^(?!api|up).*$');
