<?php

use App\Http\Controllers\ManifestController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * Ahead of the catch-all, and it has to be. Behind it the browser would be handed
 * the SPA's own HTML page with a 200 and would report a manifest it cannot parse
 * rather than one that is missing — the same quiet failure as a missing
 * `storage:link` (docs/06).
 */
Route::get('/manifest.webmanifest', ManifestController::class);

/**
 * The Vue SPA owns client-side routing. Every non-API, non-health path returns
 * the same shell so browser history / deep links resolve inside Vue Router —
 * but the shell is rendered by {@see SpaController}, which fills in the title
 * and social tags for public content and answers a renamed slug with a 301.
 */
Route::get('/', SpaController::class);
Route::get('/{any}', SpaController::class)->where('any', '^(?!api|up).*$');
