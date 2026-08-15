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

        $request->attributes->set('student_session', $session);

        return $next($request);
    }
}
