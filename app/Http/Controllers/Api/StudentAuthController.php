<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\IdentifyStudentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentAuthController extends Controller
{
    /**
     * Web identification: competitor_number + country + date of birth. All three
     * must match one active registration in the active season. Any mismatch is
     * answered uniformly so a caller cannot tell which field was wrong. On
     * success a fresh, single-active session is issued (prior ones revoked) and
     * the bearer token is returned once.
     */
    public function identify(IdentifyStudentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $registration = Registration::query()
            ->where('status', 'active')
            ->when(SeasonContext::active(), fn ($q, $season) => $q->where('season_id', $season->id))
            ->where('competitor_number', $data['competitor_number'])
            ->where('country_id', (int) $data['country_id'])
            ->whereDate('date_of_birth', $request->date('date_of_birth'))
            ->first();

        if ($registration === null) {
            return response()->json(['message' => __('We could not verify your details. Please check and try again.')], 422);
        }

        $plain = Str::random(64);

        // One active session per registration (prior ones revoked). Wrapped in a
        // transaction retried on a transient InnoDB deadlock so a concurrency
        // spike on the session table never fails a valid identification.
        $session = DB::transaction(function () use ($registration, $plain, $request) {
            StudentSession::query()->where('registration_id', $registration->id)->active()->update(['revoked_at' => now()]);

            return StudentSession::query()->create([
                'registration_id' => $registration->id,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes(StudentSession::LIFETIME_MINUTES),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);
        }, 5);

        return response()->json([
            'token' => $plain,
            'expires_at' => $session->expires_at->toIso8601String(),
            'registration' => $this->summary($registration),
        ]);
    }

    /**
     * Public country list for the identification form's dropdown. Country names
     * are not sensitive; the three-factor match still happens in identify().
     */
    public function countries(): JsonResponse
    {
        return response()->json([
            'data' => Country::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /** The current competitor's own registration and session expiry. */
    public function me(Request $request): JsonResponse
    {
        $session = $this->session($request);

        return response()->json([
            'expires_at' => $session->expires_at->toIso8601String(),
            'registration' => $this->summary($session->registration),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->session($request)->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    private function session(Request $request): StudentSession
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get('student_session');

        return $session;
    }

    /**
     * The student-safe view of a registration (never exposes internal ids or
     * other competitors' data).
     *
     * @return array<string, mixed>
     */
    private function summary(Registration $registration): array
    {
        $registration->loadMissing(['level', 'school', 'country']);

        return [
            'competitor_number' => $registration->competitor_number,
            'name' => $registration->name,
            'grade' => $registration->grade,
            'level' => $registration->level?->level_short,
            'venue' => $registration->school?->name,
            'country' => $registration->country?->name,
        ];
    }
}
