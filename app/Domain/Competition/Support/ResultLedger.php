<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the results layer (Layer B, `registration_results`) in step with the
 * in-app attempts it is derived from (ADR-0027). Publishing, unpublishing and
 * resetting an attempt all change whether it counts as an official result; after
 * each, {@see reconcile()} rebuilds the attempt-sourced Layer B rows for the
 * affected scope so the flat results table mirrors the attempts' published state.
 *
 * Import-sourced rows (`source=import`) are otherwise left alone — they have no
 * attempt behind them — except that a currently-published attempt supersedes an
 * import row for the same competitor + test (ADR-0027 precedence), so such a row
 * is dropped before the attempt row is written.
 */
final class ResultLedger
{
    /**
     * Rebuild the attempt-sourced Layer B rows for a scope of
     * (registrations × tests): drop the existing attempt rows in scope, then
     * re-insert one denormalized row per attempt that is published right now
     * (completed, `published_at` set, outside the Sample round). Idempotent and
     * uniform across publish / unpublish / reset — each just leaves the attempts
     * in a new published state that this then mirrors.
     *
     * Set-based: one DELETE plus one INSERT…SELECT, so it scales to a whole
     * publish/reset cohort. `$registrations` may be a concrete id list or a query
     * Builder yielding ids, so a large population is never pulled into PHP.
     *
     * @param  list<int>|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $registrations
     * @param  list<int>  $testIds
     */
    public static function reconcile($registrations, array $testIds): void
    {
        if ($testIds === []) {
            return;
        }

        // Constrain a query's registration column to the scope, embedding a Builder
        // as a subquery so the id set stays in the database.
        $scopeRegistrations = function ($query, string $column) use ($registrations) {
            if (is_array($registrations)) {
                return $query->whereIn($column, $registrations === [] ? [0] : $registrations);
            }

            return $query->whereIn($column, (clone $registrations));
        };

        DB::transaction(function () use ($scopeRegistrations, $testIds) {
            // A test maps to at most one active round (distinct pair), so an attempt
            // is never counted twice when its test sits in several exams.
            $testRound = DB::table('exam_test')
                ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
                ->where('exams.status', 'active')
                ->distinct()
                ->select('exam_test.test_id', 'exams.exam_round_id');

            // 1) Clear the attempt-sourced rows we are about to recompute (an
            // unpublished / reset attempt simply falls out of the re-insert below).
            $scopeRegistrations(
                DB::table('registration_results')->where('source', 'attempt')->whereIn('test_id', $testIds),
                'registration_id',
            )->delete();

            // 2) Drop any import-sourced row a currently-published attempt supersedes
            // (a published attempt is authoritative over an offline import, ADR-0027).
            // Without this the re-insert in step 3 would hit unique(registration_id,
            // test_id) whenever both an import and an in-app result exist for the same
            // competitor + test, failing the whole publish/reset cohort.
            $superseded = DB::table('registration_results')
                ->join('attempts', function ($join) {
                    $join->on('attempts.registration_id', '=', 'registration_results.registration_id')
                        ->on('attempts.test_id', '=', 'registration_results.test_id');
                })
                ->joinSub(clone $testRound, 'tr', 'tr.test_id', '=', 'attempts.test_id')
                ->join('exam_rounds', 'exam_rounds.id', '=', 'tr.exam_round_id')
                ->where('registration_results.source', 'import')
                ->whereIn('registration_results.test_id', $testIds)
                ->where('attempts.status', 'completed')
                ->whereNotNull('attempts.published_at')
                ->where('exam_rounds.is_sample', false);
            $scopeRegistrations($superseded, 'attempts.registration_id')->delete();

            // 3) Re-insert from the attempts published right now.
            $now = now()->toDateTimeString();
            $select = DB::table('attempts')
                ->join('registrations', 'registrations.id', '=', 'attempts.registration_id')
                ->joinSub(clone $testRound, 'tr', 'tr.test_id', '=', 'attempts.test_id')
                ->join('exam_rounds', 'exam_rounds.id', '=', 'tr.exam_round_id')
                ->join('tests', 'tests.id', '=', 'attempts.test_id')
                ->whereIn('attempts.test_id', $testIds)
                ->where('attempts.status', 'completed')
                ->whereNotNull('attempts.published_at')
                ->where('exam_rounds.is_sample', false)
                ->selectRaw(
                    'attempts.registration_id, attempts.test_id, tr.exam_round_id, tests.test_type_id, '
                    .'attempts.quiz_id, registrations.season_id, attempts.score, attempts.max_score, '
                    ."'attempt' as source, attempts.published_at, attempts.published_by, ? as created_at, ? as updated_at",
                    [$now, $now],
                );
            $scopeRegistrations($select, 'attempts.registration_id');

            DB::table('registration_results')->insertUsing([
                'registration_id', 'test_id', 'exam_round_id', 'test_type_id', 'quiz_id',
                'season_id', 'score', 'max_score', 'source', 'published_at', 'published_by',
                'created_at', 'updated_at',
            ], $select);
        });
    }
}
