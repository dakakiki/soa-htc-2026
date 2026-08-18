<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use Illuminate\Support\Facades\DB;

/**
 * Builds the competition-results grid shown on the Students list: for each
 * exam round (Sample excluded — it is a practice round), the score a competitor
 * scored per test type, plus the round total. Scores come only from published
 * attempts. Rounds with no tests yet (Regional Qualifiers, World final) still
 * get a column so the grid mirrors the full competition path.
 */
final class RegistrationResults
{
    /** The practice round is never shown as a results column. */
    private const EXCLUDED_ROUND = 'Sample';

    /**
     * Ordered result columns: every round but Sample, each with the test types
     * that actually appear in its tests (first letter used as the column head).
     *
     * @return list<array{round_id:int,round:string,short:string,types:list<array{id:int,name:string,letter:string}>}>
     */
    public static function columns(): array
    {
        $typeRows = DB::table('exams')
            ->join('exam_rounds', 'exam_rounds.id', '=', 'exams.exam_round_id')
            ->join('exam_test', 'exam_test.exam_id', '=', 'exams.id')
            ->join('tests', 'tests.id', '=', 'exam_test.test_id')
            ->join('test_types', 'test_types.id', '=', 'tests.test_type_id')
            ->where('exams.status', 'active')
            ->distinct()
            ->get(['exam_rounds.id as round_id', 'test_types.id as tt_id', 'test_types.name as tt_name']);

        $typesByRound = [];
        foreach ($typeRows as $r) {
            $typesByRound[$r->round_id][(int) $r->tt_id] = $r->tt_name;
        }

        $columns = [];
        foreach (DB::table('exam_rounds')->where('name', '!=', self::EXCLUDED_ROUND)->orderBy('id')->get(['id', 'name']) as $round) {
            $types = $typesByRound[$round->id] ?? [];
            ksort($types); // by test-type id → Reading first, then Writing/Speaking/Use of English

            $columns[] = [
                'round_id' => (int) $round->id,
                'round' => self::roundLabel($round->name),
                'short' => self::initials($round->name),
                'types' => array_map(
                    fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'letter' => mb_strtoupper(mb_substr($name, 0, 1))],
                    array_keys($types),
                    array_values($types),
                ),
            ];
        }

        return $columns;
    }

    /**
     * Per-registration published scores keyed by round then test-type id, with a
     * round total. Only the given registrations are queried (one page at a time).
     *
     * @param  list<int>  $registrationIds
     * @return array<int, array<int, array{types: array<int, float>, sum: float}>>
     */
    public static function forRegistrations(array $registrationIds): array
    {
        if ($registrationIds === []) {
            return [];
        }

        // A test maps to at most one (round) via this distinct pair, so an attempt
        // is never counted twice when a test happens to sit in several exams.
        $testRound = DB::table('exam_test')
            ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
            ->where('exams.status', 'active')
            ->distinct()
            ->select('exam_test.test_id', 'exams.exam_round_id');

        $rows = DB::table('attempts')
            ->joinSub($testRound, 'tr', 'tr.test_id', '=', 'attempts.test_id')
            ->join('exam_rounds', 'exam_rounds.id', '=', 'tr.exam_round_id')
            ->join('tests', 'tests.id', '=', 'attempts.test_id')
            ->whereIn('attempts.registration_id', $registrationIds)
            ->whereNotNull('attempts.published_at')
            ->where('exam_rounds.name', '!=', self::EXCLUDED_ROUND)
            ->groupBy('attempts.registration_id', 'exam_rounds.id', 'tests.test_type_id')
            ->get([
                'attempts.registration_id as reg_id',
                'exam_rounds.id as round_id',
                'tests.test_type_id as tt_id',
                DB::raw('SUM(attempts.score) as score'),
            ]);

        $out = [];
        foreach ($rows as $r) {
            $score = (float) $r->score;
            $out[(int) $r->reg_id][(int) $r->round_id]['types'][(int) $r->tt_id] = $score;
            $out[(int) $r->reg_id][(int) $r->round_id]['sum'] = ($out[(int) $r->reg_id][(int) $r->round_id]['sum'] ?? 0.0) + $score;
        }

        return $out;
    }

    /**
     * Detailed per-round breakdown for one competitor (the results modal): every
     * published attempt grouped by round, with the test title, type and score.
     *
     * @return list<array{round_id:int,round:string,tests:list<array{test:string,type:string,score:?float,max_score:?float}>}>
     */
    public static function detail(int $registrationId): array
    {
        $testRound = DB::table('exam_test')
            ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
            ->where('exams.status', 'active')
            ->distinct()
            ->select('exam_test.test_id', 'exams.exam_round_id');

        $rows = DB::table('attempts')
            ->joinSub($testRound, 'tr', 'tr.test_id', '=', 'attempts.test_id')
            ->join('exam_rounds', 'exam_rounds.id', '=', 'tr.exam_round_id')
            ->join('tests', 'tests.id', '=', 'attempts.test_id')
            ->join('test_types', 'test_types.id', '=', 'tests.test_type_id')
            ->where('attempts.registration_id', $registrationId)
            ->whereNotNull('attempts.published_at')
            ->where('exam_rounds.name', '!=', self::EXCLUDED_ROUND)
            // Latest round first — World final (if any) down to Preliminary.
            ->orderByDesc('exam_rounds.id')
            ->orderBy('test_types.id')
            ->get([
                'exam_rounds.id as round_id',
                'exam_rounds.name as round',
                'tests.title as test',
                'test_types.name as type',
                'attempts.score',
                'attempts.max_score',
            ]);

        $byRound = [];
        foreach ($rows as $r) {
            $byRound[$r->round_id]['round'] = self::roundLabel($r->round);
            $byRound[$r->round_id]['round_id'] = (int) $r->round_id;
            $byRound[$r->round_id]['tests'][] = [
                'test' => $r->test,
                'type' => $r->type,
                'score' => $r->score === null ? null : (float) $r->score,
                'max_score' => $r->max_score === null ? null : (float) $r->max_score,
            ];
        }

        return array_values($byRound);
    }

    /** Drop a trailing "round" so "Preliminary round" reads "Preliminary". */
    private static function roundLabel(string $name): string
    {
        return trim(preg_replace('/\s+round$/i', '', $name) ?? $name);
    }

    /** Initials for compact heads: "Regional Qualifiers" → "RQ", "World final" → "WF". */
    private static function initials(string $name): string
    {
        preg_match_all('/\b\w/u', $name, $m);

        return mb_strtoupper(implode('', $m[0]));
    }
}
