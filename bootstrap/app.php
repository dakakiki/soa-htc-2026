<?php

use App\Http\Middleware\EnsureStudentSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA (Vue) authenticates against the API via Sanctum cookies.
        $middleware->statefulApi();
        // Competitor (student) auth: bearer token from web identification.
        $middleware->alias(['student.session' => EnsureStudentSession::class]);

        /*
         * Where a guest is sent, as a PATH and not a named route. `Authenticate`
         * builds the redirect target before the exception is rendered, and its
         * default asks for `route('login')` — which this app does not have: the
         * login screen is a SPA route behind the web catch-all. So an `/api/...`
         * address typed straight into the address bar answered a **500 with a
         * stack trace** instead of 401, because the lookup threw before anything
         * could decide the response should be JSON. Naming the path skips the
         * lookup; `shouldRenderJsonWhen` below then still answers `api/*` with
         * 401 JSON and sends a plain browser to the login screen.
         */
        $middleware->redirectGuestsTo('/login');

        /*
         * The competitor API does NOT ride on the web session, so it must not be
         * gated by the web session's CSRF token.
         *
         * `statefulApi()` puts every `/api/*` call from this domain behind the
         * session and its CSRF check. For the staff SPA that is exactly right —
         * it authenticates by cookie. The competitor does not: `student.session`
         * reads a bearer token and nothing else, so a forged cross-site request
         * arrives without the header and is refused with 401 either way. There is
         * nothing for CSRF to protect here, and one thing for it to break: when
         * the web session ages out (SESSION_LIFETIME), the XSRF cookie stops
         * matching and a competitor in the middle of a contest gets «CSRF token
         * mismatch» on starting or handing in a test — over a session they never
         * had a use for. Reported from the exam screen on 2026-08-25.
         *
         * `identify` is exempt too, and safely: it is not authenticated by a
         * cookie, a caller must already know the three factors it checks, and the
         * bearer token it answers with cannot be read cross-origin.
         *
         * 🪤 Singular on purpose. The admin roster lives under `api/registrations/*`;
         * `api/student/*` matches no staff route (`api/students/…` would not match
         * this pattern either — the segment boundary is part of it).
         */
        $middleware->validateCsrfTokens(except: ['api/student/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
