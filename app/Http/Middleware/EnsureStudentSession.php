<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Competition\Models\StudentSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a competitor from the bearer token issued at web identification.
 * Resolves the active (non-revoked, non-expired) student session and shares it,
 * plus its registration, on the request for the controllers to use.
 *
 * The session expires on a SLIDING horizon (owner, 2026-08-25): every
 * authenticated call pushes it out again. Fixed from identification it measured
 * the wrong thing — a competitor who identified when the room opened and then
 * waited for the invigilator could cross it in the middle of a test, and lose
 * the test to a clock that had nothing to do with the test's own. Now the horizon
 * measures what it should: how long since the competitor last did anything.
 *
 * ⚠️ This does NOT touch the attempt's clock. A test still runs out when its own
 * duration is up (ADR-0018); only the right to be here at all slides.
 */
class EnsureStudentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token === null ? null : StudentSession::query()
            ->active()
            ->with('registration')
            ->firstWhere('token_hash', hash('sha256', $token));

        if ($session === null || $session->registration === null) {
            abort(401, 'Unauthenticated.');
        }

        self::extend($session);

        $request->attributes->set('student_session', $session);

        return $next($request);
    }

    /**
     * Push the session's horizon back to a full lifetime.
     *
     * Written at most once a minute per session rather than on every call: this
     * runs on every authenticated competitor request, and re-stamping a horizon
     * that is already all but full buys nothing but a write. A session refreshed
     * within the last minute is left alone.
     */
    private static function extend(StudentSession $session): void
    {
        $full = now()->addMinutes(StudentSession::LIFETIME_MINUTES);

        if ($session->expires_at->greaterThan($full->copy()->subMinute())) {
            return;
        }

        $session->forceFill(['expires_at' => $full])->save();
    }
}
