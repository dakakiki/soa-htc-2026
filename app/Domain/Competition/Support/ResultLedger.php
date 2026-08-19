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
 * Import-sourced rows (`source=import`) are never touched here — they have no
 * attempt behind them.
 */
final class ResultLedger
{
    /** The practice round is never recorded as an official result. */
    private const EXCLUDED_ROUND = 'Sample';

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
            // 1) Clear the attempt-sourced rows we are about to recompute (import
            // rows are left alone — they have no attempt behind them).
            $scopeRegistrations(
                DB::table('registration_results')->where('source', 'attempt')->whereIn('test_id', $testIds),
                'registration_id',
            )->delete();

            // 2) Re-insert from the attempts published right now. A test maps to at
            // most one active round (distinct pair), so an attempt is never counted
            // twice when its test sits in several exams.
            $testRound = DB::table('exam_test')
                ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
                ->where('exams.status', 'active')
                ->distinct()
                ->select('exam_test.test_id', 'exams.exam_round_id');

            $now = now()->toDateTimeString();
            $select = DB::table('attempts')
                ->join('registrations', 'registrations.id', '=', 'attempts.registration_id')
                ->joinSub($testRound, 'tr', 'tr.test_id', '=', 'attempts.test_id')
                ->join('exam_rounds', 'exam_rounds.id', '=', 'tr.exam_round_id')
                ->join('tests', 'tests.id', '=', 'attempts.test_id')
                ->whereIn('attempts.test_id', $testIds)
                ->where('attempts.status', 'completed')
                ->whereNotNull('attempts.published_at')
                ->where('exam_rounds.name', '!=', self::EXCLUDED_ROUND)
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
