<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Keep the environment inside the request instead of the process.
//
// Apache on Windows (mpm_winnt) serves every request from a single process with
// many threads, and mod_php runs inside it. phpdotenv's default adapter writes
// each variable with putenv(), which is process-global and not thread safe, so
// concurrent requests can observe a half-populated environment: env() then falls
// back to the config defaults and the request fails with the wrong database
// driver or a missing APP_KEY. Writing only to $_ENV/$_SERVER keeps the values
// per request. Nothing in this application reads getenv() directly.
Env::disablePutenv();

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
