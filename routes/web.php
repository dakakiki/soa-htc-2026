<?php

use Illuminate\Support\Facades\Route;

/**
 * The Vue SPA owns client-side routing. Every non-API, non-health path returns
 * the same shell so browser history / deep links resolve inside Vue Router.
 */
Route::view('/', 'app');
Route::view('/{any}', 'app')->where('any', '^(?!api|up).*$');
