<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each competitor actually answered, question by question — Layer A.
 *
 * The marks are already here from {@see ImportLegacyResults}; this is what sits
 * underneath them, and it is what the results export "with answers" reads.
 *
 * 🔴 It cannot cover everything, and the reason is in the legacy database rather
 * than in this command: **publishing a competition result deletes its answers
 * there**. Cross-tabulated over the 2026-08-29 dump, 106,294 published results
 * have no answer rows at all while only 10 unpublished ones are missing them.
 * So per-question detail exists for the sample rounds and for marks that have
 * not been released — 78,347 of 184,651. Nothing recovers the rest; it is gone
 * at the source. Our own application does not do this, so what is thin is the
 * migrated history and not what happens from here.
 *
 * 🪤 It wants more memory than PHP's command line usually offers. Two million
 * answers is the largest thing this application has ever moved in one command,
 * and 128 MB — the default here — is not enough at any chunk size:
 *
 *     php -d memory_limit=1G artisan legacy:import-answers
 *
 * `--chunk` is the other lever, and it counts competitors rather than rows: 250
 * reads about fifteen thousand answer rows per pass. Lower it before raising the
 * limit if a server will not give you one.
 *
 * 🪤 The attempt's score stays the legacy mark. Correctness is filled in for
 * multiple choice, where our key answers it exactly, and left empty for gaps and
 * essays: the grader's normalisation is its own, and a second copy of those rules
 * living here would drift from it. The mark a competitor was given is the one
 * legacy gave them either way.
 */
class ImportLegacyAnswers extends Command
{
    protected $signature = 'legacy:import-answers
        {--chunk=250 : competitors read per pass — see the note on memory}
        {--dry-run : report what would happen and write nothing}';

    protected $description = 'Import the legacy per-question answers of the round in play into Layer A';

    public function handle(): int
    {
        $season = Season::query()->where('status', 'active')->first();

        if ($season === null) {
            $this->error('No active season.');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');
        $dryRun = (bool) $this->option('dry-run');

        $registrations = DB::table('registrations')
            ->where('season_id', $season->id)
            ->whereNotNull('legacy_id')
            ->pluck('id', 'legacy_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($registrations === []) {
            $this->error('No imported registrations. Run legacy:import-registrations first.');

            return self::FAILURE;
        }

        $tests = DB::table('tests')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->map(fn ($id) => (int) $id)->all();
        $durations = DB::table('tests')->pluck('duration', 'id')->all();
        $quizzes = DB::table('quizzes')->whereNotNull('legacy_id')->get(['id', 'legacy_id', 'quiz_type'])->keyBy('legacy_id');

        // question legacy id => [our id, type, how many blanks it has]
        $questions = [];
        foreach (DB::table('questions')->whereNotNull('legacy_id')->get(['id', 'legacy_id', 'question_type', 'description']) as $q) {
            $questions[(int) $q->legacy_id] = [
                'id' => (int) $q->id,
                'type' => (string) $q->question_type,
                'gaps' => substr_count((string) $q->description, '[answer]'),
            ];
        }

        // reply legacy id => [our answer id, its position, whether it is the key]
        $replies = [];
        foreach (DB::table('question_answers')->whereNotNull('legacy_id')->get(['id', 'legacy_id', 'position', 'is_correct']) as $a) {
            $replies[(int) $a->legacy_id] = [
                'id' => (int) $a->id,
                'position' => (int) $a->position,
                'correct' => (bool) $a->is_correct,
            ];
        }

        /*
         * 🪤 Marks are read per pass rather than all at once. There are 184,384
         * of them, and PHP's command line runs with 128 MB here — holding them
         * killed the process outright, with no message and exit 255. Nothing
         * about this command should depend on how much memory a server gives it.
         */

        $legacyIds = array_keys($registrations);
        $counts = ['attempts' => 0, 'answers' => 0, 'no_question' => 0, 'no_test' => 0];
        $bar = $this->output->createProgressBar(count($legacyIds));
        $bar->start();

        foreach (array_chunk($legacyIds, (int) $this->option('chunk')) as $slice) {
            $this->importSlice(
                $legacy, $season, $slice, $registrations, $tests, $durations,
                $quizzes, $questions, $replies, $dryRun, $counts,
            );
            $bar->advance(count($slice));
        }

        $bar->finish();
        $this->newLine(2);

        $verb = $dryRun ? 'Would write' : 'Wrote';
        $this->info("{$verb} {$counts['attempts']} attempts and {$counts['answers']} answers.");

        if ($counts['no_question'] > 0) {
            $this->line("Answer rows whose question is not ours: {$counts['no_question']}.");
        }
        if ($counts['no_test'] > 0) {
            $this->line("Answer rows whose test is not ours: {$counts['no_test']}.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $slice
     * @param  array<int, int>  $registrations
     * @param  array<int, int>  $tests
     * @param  array<int, array{id:int,type:string,gaps:int}>  $questions
     * @param  array<int, array{id:int,position:int,correct:bool}>  $replies
     * @param  array<string, int>  $counts
     */
    private function importSlice(
        Connection $legacy,
        Season $season,
        array $slice,
        array $registrations,
        array $tests,
        array $durations,
        $quizzes,
        array $questions,
        array $replies,
        bool $dryRun,
        array &$counts,
    ): void {
        /*
         * Grouped in the database rather than in PHP: a gap-filling question is
         * one row per blank, and a group must never be split across a pass. The
         * slice is a set of competitors, and a competitor's rows are all here.
         */
        $rows = $legacy->table('test_results')
            ->whereIn('student_id', $slice)
            ->groupBy('student_id', 'test_id', 'quiz_id', 'question_id')
            ->orderBy('student_id')
            ->selectRaw('student_id, test_id, quiz_id, question_id,
                group_concat(reply_id order by id) as replies,
                group_concat(coalesce(answer, "") order by id separator 0x1f) as answers,
                min(created_at) as first_seen, max(created_at) as last_seen')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // The marks of these competitors only, keyed the way an attempt is.
        $marks = [];
        $mine = array_values(array_intersect_key($registrations, array_flip($slice)));
        foreach (DB::table('registration_results')
            ->where('season_id', $season->id)
            ->whereIn('registration_id', $mine)
            ->get(['registration_id', 'test_id', 'score', 'published_at']) as $m) {
            $marks[$m->registration_id.'-'.$m->test_id] = $m;
        }

        // What an attempt is: one competitor, one test.
        $attempts = [];
        $answers = [];

        foreach ($rows as $r) {
            $registrationId = $registrations[(int) $r->student_id] ?? null;
            $testId = $tests[(int) $r->test_id] ?? null;

            if ($registrationId === null || $testId === null) {
                $counts['no_test']++;

                continue;
            }

            $key = $registrationId.'-'.$testId;
            $quiz = $quizzes[(int) $r->quiz_id] ?? null;

            if (! isset($attempts[$key])) {
                $mark = $marks[$key] ?? null;
                $started = $r->first_seen;
                $duration = (int) ($durations[$testId] ?? 0);

                $attempts[$key] = [
                    'registration_id' => $registrationId,
                    'test_id' => $testId,
                    'quiz_id' => $quiz?->id,
                    'is_practice' => $quiz !== null && (string) $quiz->quiz_type === 'sample',
                    'status' => 'completed',
                    'score' => $mark?->score,
                    'max_score' => null,
                    'grading_status' => 'graded',
                    'published_at' => $mark?->published_at,
                    'started_at' => $started,
                    // Never open: the window is reconstructed from the test's own
                    // length so the row is consistent, not so anybody reads it.
                    'expires_at' => $this->plusMinutes($started, $duration),
                    'submitted_at' => $r->last_seen,
                    'channel' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                // A later question of the same test moves the finish line.
                if ($r->last_seen > $attempts[$key]['submitted_at']) {
                    $attempts[$key]['submitted_at'] = $r->last_seen;
                }
            }

            $question = $questions[(int) $r->question_id] ?? null;
            if ($question === null) {
                $counts['no_question']++;

                continue;
            }

            $answers[] = [
                'key' => $key,
                'question_id' => $question['id'],
                'response' => $this->response($question, (string) $r->replies, (string) $r->answers, $replies),
                'is_correct' => $this->correctness($question, (string) $r->replies, $replies),
            ];
        }

        if ($dryRun) {
            $counts['attempts'] += count($attempts);
            $counts['answers'] += count($answers);

            return;
        }

        $ids = $this->writeAttempts($attempts);
        $counts['attempts'] += count($attempts);

        $write = [];
        foreach ($answers as $a) {
            $attemptId = $ids[$a['key']] ?? null;
            if ($attemptId === null) {
                continue;
            }
            $write[] = [
                'attempt_id' => $attemptId,
                'question_id' => $a['question_id'],
                'response' => $a['response'],
                'is_correct' => $a['is_correct'],
                'awarded_points' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($write, 2000) as $batch) {
            DB::table('attempt_answers')->upsert($batch, ['attempt_id', 'question_id'], ['response', 'is_correct', 'updated_at']);
        }

        $counts['answers'] += count($write);
    }

    /**
     * @param  array<string, array<string, mixed>>  $attempts
     * @return array<string, int> key => attempt id
     */
    private function writeAttempts(array $attempts): array
    {
        $existing = DB::table('attempts')
            ->whereIn('registration_id', array_column($attempts, 'registration_id'))
            ->get(['id', 'registration_id', 'test_id']);

        $ids = [];
        foreach ($existing as $e) {
            $ids[$e->registration_id.'-'.$e->test_id] = (int) $e->id;
        }

        $insert = [];
        foreach ($attempts as $key => $attempt) {
            if (! isset($ids[$key])) {
                $insert[] = $attempt;
            }
        }

        foreach (array_chunk($insert, 1000) as $batch) {
            DB::table('attempts')->insert($batch);
        }

        if ($insert !== []) {
            foreach (DB::table('attempts')
                ->whereIn('registration_id', array_column($insert, 'registration_id'))
                ->get(['id', 'registration_id', 'test_id']) as $e) {
                $ids[$e->registration_id.'-'.$e->test_id] = (int) $e->id;
            }
        }

        return $ids;
    }

    /**
     * The competitor's answer in the shape the exam screen writes it, so an
     * imported attempt reads back exactly like one sat here.
     *
     * @param  array{id:int,type:string,gaps:int}  $question
     * @param  array<int, array{id:int,position:int,correct:bool}>  $replies
     */
    private function response(array $question, string $replyIds, string $texts, array $replies): string
    {
        $ids = array_filter(array_map('intval', explode(',', $replyIds)));
        $written = explode("\x1f", $texts);

        if ($question['type'] === 'multiple_choice') {
            $selected = [];
            foreach ($ids as $id) {
                if (isset($replies[$id])) {
                    $selected[] = $replies[$id]['id'];
                }
            }

            return (string) json_encode(['selected' => $selected]);
        }

        if ($question['type'] === 'gap_filling') {
            // One legacy row per blank; the blank it belongs to is the reply's
            // own order, which is the position our answer rows carry.
            $gaps = array_fill(0, max($question['gaps'], count($ids)), '');
            foreach ($ids as $i => $id) {
                $at = isset($replies[$id]) ? $replies[$id]['position'] - 1 : $i;
                if ($at >= 0 && $at < count($gaps)) {
                    $gaps[$at] = trim($written[$i] ?? '');
                }
            }

            return (string) json_encode(['gaps' => array_slice($gaps, 0, max($question['gaps'], 1))]);
        }

        return (string) json_encode(['text' => trim(implode(' ', array_filter($written)))]);
    }

    /**
     * @param  array{id:int,type:string,gaps:int}  $question
     * @param  array<int, array{id:int,position:int,correct:bool}>  $replies
     */
    private function correctness(array $question, string $replyIds, array $replies): ?bool
    {
        if ($question['type'] !== 'multiple_choice') {
            return null;
        }

        $ids = array_filter(array_map('intval', explode(',', $replyIds)));

        foreach ($ids as $id) {
            if (isset($replies[$id])) {
                return $replies[$id]['correct'];
            }
        }

        return null;
    }

    private function plusMinutes(?string $from, int $minutes): string
    {
        $start = $from === null ? now() : Carbon::parse($from);

        return $start->copy()->addMinutes(max($minutes, 1))->format('Y-m-d H:i:s');
    }
}
