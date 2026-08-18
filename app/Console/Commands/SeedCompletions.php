<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Enums\QuestionType;
use App\Domain\Competition\Enums\AttemptStatus;
use App\Domain\Competition\Enums\GradingStatus;
use App\Domain\Competition\Jobs\GradeAttempt;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Support\AttemptGrader;
use App\Domain\Organization\Support\SeasonContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only fixture: simulates a cohort of competitors completing every
 * essay-free test of a quiz, with a *controlled* mix of right/wrong/blank
 * answers, then hands each attempt to the real grading path. Lets us measure
 * the grade -> publish flow (Faza 6, korak 1) at realistic volume and verify
 * the auto-grader against ground truth. Never for production.
 *
 * Correctness is deterministic per (registration, question) so an independent
 * verifier can reproduce the intended outcome without any shared state.
 */
class SeedCompletions extends Command
{
    protected $signature = 'dev:seed-completions
        {--dl=19 : difficulty level whose registrations form the cohort}
        {--quiz=6 : quiz whose essay-free tests get completed}
        {--limit=2083 : cap on competitors}
        {--grade-inline : grade synchronously instead of dispatching GradeAttempt jobs}';

    protected $description = '[dev] Simulate competitors completing a quiz with controlled answers, then grade';

    /** Per-competitor target accuracy tiers, picked round-robin for a score spread. */
    private const ACCURACY_TIERS = [95, 80, 60, 40, 20];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $season = SeasonContext::active();
        if ($season === null) {
            $this->error('No active season.');

            return self::FAILURE;
        }

        $dl = (int) $this->option('dl');
        $quizId = (int) $this->option('quiz');
        $limit = (int) $this->option('limit');
        $inline = (bool) $this->option('grade-inline');

        // Resolve the quiz's essay-free tests that also belong to this level,
        // with each question's correct/distractor answer ids precomputed once.
        $tests = $this->resolveTests($quizId, $dl);
        if ($tests === []) {
            $this->error("No essay-free tests found for quiz {$quizId} at difficulty level {$dl}.");

            return self::FAILURE;
        }
        $this->info('Tests: '.implode(', ', array_map(
            fn ($t) => "#{$t['id']} ({$t['title']}, ".count($t['questions'])."q/{$t['maxScore']}pts)",
            $tests,
        )));

        $registrations = DB::table('registrations')
            ->where('season_id', $season->id)
            ->where('difficulty_level_id', $dl)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        if ($registrations->isEmpty()) {
            $this->error("No active registrations at difficulty level {$dl}.");

            return self::FAILURE;
        }

        $this->info("Completing {$registrations->count()} competitors x ".count($tests).' tests'
            .($inline ? ' (grading inline)' : ' (dispatching GradeAttempt jobs)').'…');
        $bar = $this->output->createProgressBar($registrations->count() * count($tests));

        $now = Carbon::now();
        $ts = $now->toDateTimeString();
        $attemptsMade = 0;
        $answersMade = 0;

        foreach ($registrations as $i => $reg) {
            $targetPct = self::ACCURACY_TIERS[$i % count(self::ACCURACY_TIERS)];

            foreach ($tests as $test) {
                $attempt = Attempt::firstOrCreate(
                    ['registration_id' => $reg->id, 'test_id' => $test['id']],
                    [
                        'quiz_id' => $test['quizId'],
                        'status' => AttemptStatus::Completed,
                        'grading_status' => GradingStatus::Queued,
                        'started_at' => $now,
                        'expires_at' => $now->copy()->addMinutes((int) $test['duration']),
                        'submitted_at' => $now,
                        'channel' => 'web',
                    ],
                );

                $rows = $this->buildAnswerRows($attempt->id, (int) $reg->id, $test['questions'], $targetPct, $ts);
                if ($rows !== []) {
                    DB::table('attempt_answers')->insertOrIgnore($rows);
                    $answersMade += count($rows);
                }

                $inline ? AttemptGrader::grade($attempt) : GradeAttempt::dispatch($attempt);

                $attemptsMade++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Attempts: {$attemptsMade}  Answers: {$answersMade}");
        $this->info($inline
            ? 'Graded inline. Ready to publish.'
            : 'Jobs queued. Run: php artisan queue:work --stop-when-empty');

        return self::SUCCESS;
    }

    /**
     * @return list<array{id:int,title:string,duration:int,quizId:int,questions:list<array{id:int,points:float,correct:list<int>,distractor:?int}>,maxScore:float}>
     */
    private function resolveTests(int $quizId, int $dl): array
    {
        $examIds = DB::table('exam_quiz')->where('quiz_id', $quizId)->pluck('exam_id')->all();
        $dlTestIds = DB::table('difficulty_level_test')->where('difficulty_level_id', $dl)->pluck('test_id')->all();

        $testIds = DB::table('exam_test')
            ->whereIn('exam_id', $examIds)
            ->whereIn('test_id', $dlTestIds)
            ->distinct()
            ->pluck('test_id')
            ->all();

        $tests = [];
        foreach ($testIds as $testId) {
            $questionRows = DB::table('question_test')
                ->join('questions', 'questions.id', '=', 'question_test.question_id')
                ->where('question_test.test_id', $testId)
                ->where('questions.status', 'active')
                ->orderBy('question_test.position')
                ->get(['questions.id', 'questions.question_type', 'questions.points']);

            // Skip any test carrying an essay — those land in pending_grading (not auto-publishable).
            if ($questionRows->contains(fn ($q) => $q->question_type === QuestionType::Essay->value)) {
                continue;
            }
            if ($questionRows->isEmpty()) {
                continue;
            }

            // One query for all this test's answer options, grouped by question.
            $answersByQuestion = DB::table('question_answers')
                ->whereIn('question_id', $questionRows->pluck('id'))
                ->get(['id', 'question_id', 'is_correct'])
                ->groupBy('question_id');

            $questions = [];
            foreach ($questionRows as $q) {
                $opts = $answersByQuestion->get($q->id, collect());
                $questions[] = [
                    'id' => (int) $q->id,
                    'points' => (float) $q->points,
                    'correct' => $opts->where('is_correct', 1)->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'distractor' => optional($opts->firstWhere('is_correct', 0))->id,
                ];
            }

            $test = DB::table('tests')->find($testId);
            $tests[] = [
                'id' => (int) $testId,
                'title' => (string) $test->title,
                'duration' => (int) $test->duration,
                'quizId' => $quizId,
                'questions' => $questions,
                'maxScore' => (float) $questionRows->sum('points'),
            ];
        }

        return $tests;
    }

    /**
     * Build controlled answer rows from precomputed question metadata.
     * Right/wrong is deterministic per (registration, question); ~4% are left
     * blank (no row) to exercise the unanswered path. Right answer = the exact
     * correct id set; wrong = a single distractor.
     *
     * @param  list<array{id:int,points:float,correct:list<int>,distractor:?int}>  $questions
     * @return list<array<string,mixed>>
     */
    private function buildAnswerRows(int $attemptId, int $regId, array $questions, int $targetPct, string $ts): array
    {
        $rows = [];

        foreach ($questions as $q) {
            if (crc32("{$regId}:skip:{$q['id']}") % 25 === 0) {
                continue; // ~4% unanswered
            }

            $makeCorrect = (crc32("{$regId}:{$q['id']}") % 100) < $targetPct;

            $selected = ($makeCorrect || $q['distractor'] === null)
                ? $q['correct']
                : [(int) $q['distractor']];

            $rows[] = [
                'attempt_id' => $attemptId,
                'question_id' => $q['id'],
                'response' => json_encode(['selected' => $selected]),
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        return $rows;
    }
}
