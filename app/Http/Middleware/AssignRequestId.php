<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every request a name, and put that name on everything it writes (ADR-0070).
 *
 * The application had no way to answer the only question an operator ever really
 * has: "a coordinator says it broke at ten past nine — which of these lines is
 * theirs?" A stack trace in `laravel.log` said what went wrong and nothing about
 * who it happened to, on which screen, or which of the other forty lines around
 * it belong to the same request.
 *
 * So each request gets an id, the id goes into `Log::withContext` — which every
 * later line in that request inherits, the exception handler's report included —
 * and it comes back in the `X-Request-Id` response header. The reader can quote
 * the id off the screen; the operator greps for it and has the whole request.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Twelve characters, lower case. Not a UUID on purpose: this is a string
     * somebody reads off a screen and types into a search, and thirty-six
     * characters of hyphenated hex is a string people get wrong.
     */
    private const LENGTH = 12;

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->inherited($request) ?? Str::lower(Str::random(self::LENGTH));

        // Available to anything that wants to name the request without reaching
        // for the logger — the exception renderer, say.
        $request->attributes->set('request_id', $id);

        /*
         * The user is deliberately absent here: authentication has not run yet at
         * this point in the stack. It is added the moment a guard resolves one,
         * from the `Authenticated` listener in AppServiceProvider — so a line
         * written before sign-in is honestly anonymous rather than wrongly
         * attributed.
         */
        // 🪤 `shareContext`, not `withContext`. On the manager the latter is
        // proxied to the DEFAULT channel only; this has to reach whatever the
        // request ends up writing through.
        Log::shareContext([
            'request_id' => $id,
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * An id the caller already had, when it is safe to keep.
     *
     * A proxy or a load balancer in front of the application usually stamps one,
     * and honouring it is what lets its logs and these line up. But it is a
     * header, which means it is a stranger's text going into a log file:
     *
     * 🪤 Unfiltered, a newline in it would let anyone forge log lines — write
     * `abc\n[2026-08-29 09:00:00] production.ERROR: nothing to see here` and the
     * file says what the caller wanted it to say. So the value is accepted only
     * as the shape of an id: letters, digits and the three separators that ids
     * are actually written with, and short enough not to bury the line.
     */
    private function inherited(Request $request): ?string
    {
        $value = (string) $request->headers->get(self::HEADER, '');

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1 ? $value : null;
    }
}
