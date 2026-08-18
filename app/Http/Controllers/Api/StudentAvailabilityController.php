<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Competition\Support\StudentAvailability;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentAvailabilityController extends Controller
{
    /**
     * The assessment tree the current competitor may see (CC-06), gated by the
     * registration's difficulty level. The client renders these statuses but is
     * never the authority — every start still re-checks server-side (Faza 4).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(StudentAvailability::for($this->session($request)));
    }

    /**
     * Clear a competition quiz's access code for this session. Every denial —
     * quiz outside the registration's level, quiz not password-gated, or a wrong
     * code — returns the same 422 so a caller learns nothing from the response.
     */
    public function unlock(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $password = (string) $request->input('password', '');

        $quiz = Quiz::query()
            ->where('status', 'active')
            ->whereKey((int) $request->route('quiz'))
            ->whereHas('levels', fn ($q) => $q->whereKey($session->registration->difficulty_level_id))
            ->first();

        if ($quiz === null || ! $quiz->requiresPassword() || ! $quiz->passwordMatches($password)) {
            return response()->json(['message' => __('We could not unlock this quiz. Please check the password and try again.')], 422);
        }

        // Retry on a transient deadlock on the session-quiz pivot under load.
        DB::transaction(function () use ($session, $quiz) {
            $session->unlockedQuizzes()->syncWithoutDetaching([$quiz->id => ['unlocked_at' => now()]]);
        }, 5);

        return response()->json(['unlocked' => true]);
    }

    private function session(Request $request): StudentSession
    {
        /** @var StudentSession $session */
        $session = $request->attributes->get('student_session');

        return $session;
    }
}
