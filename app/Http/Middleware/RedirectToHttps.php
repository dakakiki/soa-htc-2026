<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a visitor who arrived over plain HTTP to the same address over HTTPS.
 *
 * On HTTP the site does not fail — it half-works, which is worse, because nothing
 * says so. The front page renders and looks finished; then everything that needs a
 * session quietly does not. `SESSION_SECURE_COOKIE=true` (`.env.example`) means the
 * browser is never handed the session cookie, so signing in appears to do nothing
 * and reports nothing. A service worker does not register outside a secure context
 * either, so the application cannot be installed (ADR-0073), and the signed
 * addresses exam media are fetched with (ADR-0059) are computed from the scheme.
 *
 * Measured on the staging server 2026-09-13: `http://staging.soa-htc.com/` answered
 * **200 with the whole application** and no redirect.
 *
 * The gate is `APP_URL`, and deliberately not the environment. A site whose own
 * address is `https://` is a site where HTTP cannot work; one whose address is
 * `http://` — the development vhost — has to keep working exactly as it does.
 * Nothing here names a hostname, so there is nothing to keep in step with whatever
 * the development machine is called this month.
 */
class RedirectToHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure() && $this->siteIsHttps()) {
            /*
             * 301 rather than 302: for this application HTTPS-only is a permanent
             * property and not a deployment detail — the secure cookie makes the
             * plain-HTTP site unusable by construction, not by configuration.
             */
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }

    private function siteIsHttps(): bool
    {
        return str_starts_with((string) config('app.url'), 'https://');
    }
}
