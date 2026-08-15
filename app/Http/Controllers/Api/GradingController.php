<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Assessment\Models\Question;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\AttemptAnswer;
use App\Domain\Competition\Models\GradeRevision;
use App\Domain\Competition\Support\AttemptGrader;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin essay grading (Faza 5 Slice 5b, CC-09). Auto-gradable parts are already
 * scored; here a grader awards points and a note per essay, and any correction
 * keeps the previous value and a required reason (audit).
 */
class GradingController extends Controller
{
    /** Attempts waiting for essay grading. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('results.manage');

        $attempts = Attempt::query()
            ->where('grading_status', GradingStatus::PendingGrading)
            ->with(['registration:id,competitor_number,name', 'test:id,title'])
            ->orderBy('submitted_at')
            ->paginate(20);

        return response()->json([
            'data' => $attempts->getCollection()->map(fn (Attempt $attempt) => [
                'id' => $attempt->id,
                'competitor_number' => $attempt->registration?->competitor_number,
                'name' => $attempt->registration?->name,
                'test' => $attempt->test?->title,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => (float) $attempt->score,
                'max_score' => (float) $attempt->max_score,
            ])->all(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    /** One attempt's essays with the competitor's responses and current grades. */
    public function show(Attempt $attempt): JsonResponse
    {
        $this->authorize('results.manage');

        $attempt->load(['registration:id,competitor_number,name', 'test:id,title']);
        $test = $attempt->test;
        $test->load(['questions' => fn ($q) => $q->where('questions.status', 'active')->where('question_type', 'essay')]);
        $answers = $attempt->answers()->with('grader:id,name')->get()->keyBy('question_id');

        $essays = $test->questions->map(function (Question $question) use ($answers) {
            $answer = $answers->get($question->id);

            return [
                'answer_id' => $answer?->id,
                'question_title' => $question->title,
                'question_description' => $question->description,
                'points' => (float) $question->points,
                'response' => is_array($answer?->response) ? ($answer->response['text'] ?? null) : null,
                'awarded_points' => $answer?->awarded_points !== null ? (float) $answer->awarded_points : null,
                'grade_note' => $answer?->grade_note,
                'graded_at' => $answer?->graded_at?->toIso8601String(),
                'graded_by' => $answer?->grader?->name,
            ];
        })->values();

        return response()->json([
            'attempt' => [
                'id' => $attempt->id,
                'competitor_number' => $attempt->registration?->competitor_number,
                'name' => $attempt->registration?->name,
                'test' => $test->title,
                'score' => (float) $attempt->score,
                'max_score' => (float) $attempt->max_score,
                'grading_status' => $attempt->grading_status?->value,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            ],
            'essays' => $essays,
        ]);
    }

    /** Grade (or re-grade) one essay answer. */
    public function gradeEssay(Request $request, Attempt $attempt, AttemptAnswer $answer): JsonResponse
    {
        $this->authorize('results.manage');

        $answer->load('question');
        if ($answer->attempt_id !== $attempt->id || $answer->question?->question_type !== QuestionType::Essay) {
            abort(404);
        }

        $alreadyGraded = $answer->graded_at !== null;
        $validated = $request->validate([
            'awarded_points' => ['required', 'numeric', 'min:0', 'max:'.(float) $answer->question->points],
            'note' => ['nullable', 'string', 'max:2000'],
            // A correction must say why (keeps the previous value in the audit trail).
            'reason' => [$alreadyGraded ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $attempt, $answer, $validated, $alreadyGraded) {
            if ($alreadyGraded) {
                GradeRevision::create([
                    'attempt_answer_id' => $answer->id,
                    'previous_points' => $answer->awarded_points,
                    'previous_note' => $answer->grade_note,
                    'reason' => $validated['reason'],
                    'graded_by' => $request->user()?->id,
                ]);
            }

            $answer->update([
                'awarded_points' => $validated['awarded_points'],
                'grade_note' => $validated['note'] ?? null,
                'graded_by' => $request->user()?->id,
                'graded_at' => now(),
            ]);

            AttemptGrader::recompute($attempt);
        });

        return response()->json([
            'grading_status' => $attempt->refresh()->grading_status?->value,
            'score' => (float) $attempt->score,
        ]);
    }
}
