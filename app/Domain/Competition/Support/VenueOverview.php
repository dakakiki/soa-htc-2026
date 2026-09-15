<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Support\SampleRound;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a coordinator's room looks like from the outside: which papers are open
 * across the venues they run, how far the room has got with each, and what has
 * been marked and published.
 *
 * 🔴 The CONTEST only, and the boundary is {@see SampleRound} — practice is what
 * a practice ROUND says it is, never a quiz's name or type (ADR-0084). A
 * coordinator's numbers are about the competition their venue is sitting; a
 * child practising at home has nothing to do with the room, and adding the two
 * would produce a figure that measures neither.
 *
 * 🔴 An AVERAGE is only ever computed over PUBLISHED attempts, which is also
 * why it appears in `published()` and never in `open()`. The owner's rule of
 * 2026-09-15: while the room is still working, an average says something
 * different every time it is read — so it is not a number, it is a moving one.
 *
 * Three counts, and each measures what its name says (ADR-0084–0087):
 *
 *  - **entered** — CHILDREN on these venues' rosters whose difficulty level puts
 *    this paper in front of them. Not attempts: it is who was expected.
 *  - **started** — children who have opened it.
 *  - **submitted** — children who have handed it in.
 *
 * All three count children and not attempts, so a retaken paper cannot inflate
 * a room's turnout.
 */
final class VenueOverview
{
    /**
     * The papers open across these venues right now.
     *
     * "Open" is the same three-layer `active` the candidate's own screen obeys —
     * quiz, exam and test each have to be active, because an administrator can
     * retire any one of the three ({@see StudentAvailability}). It deliberately
     * does NOT include the exam password: what a coordinator may SEE is a
     * different question from what a child may ENTER, and a room whose paper is
     * open is a room that wants its numbers whether the password has been read
     * out yet or not.
     *
     * @param  list<int>  $schoolIds
     * @return list<array<string, mixed>>
     */
    public static function open(array $schoolIds, int $seasonId): array
    {
        return self::rows($schoolIds, $seasonId, published: false);
    }

    /**
     * The papers these venues have sat and an administrator has published.
     *
     * A row appears only once there is at least one published mark behind it: a
     * paper that has been sat but not released is not a result yet, and
     * printing it with an empty average would be reporting a number nobody has
     * approved (ADR-0021).
     *
     * @param  list<int>  $schoolIds
     * @return list<array<string, mixed>>
     */
    public static function published(array $schoolIds, int $seasonId): array
    {
        return self::rows($schoolIds, $seasonId, published: true);
    }

    /**
     * @param  list<int>  $schoolIds
     * @return list<array<string, mixed>>
     */
    private static function rows(array $schoolIds, int $seasonId, bool $published): array
    {
        if ($schoolIds === []) {
            return [];
        }

        $entered = self::enteredPerTest($schoolIds, $seasonId);
        $attempts = self::attemptsPerTest($schoolIds, $seasonId);

        $rows = [];

        foreach (self::contestTree() as $quiz) {
            foreach ($quiz->exams as $exam) {
                foreach ($exam->tests as $test) {
                    $counts = $attempts[$test->id] ?? null;
                    $hasPublished = ($counts['published'] ?? 0) > 0;

                    /*
                     * Published rows need a published mark; open rows need the
                     * paper to be in front of somebody. A test at a level nobody
                     * at these venues sits is not this coordinator's business.
                     */
                    if ($published ? ! $hasPublished : ! isset($entered[$test->id])) {
                        continue;
                    }

                    $row = [
                        'test_id' => $test->id,
                        'quiz' => $quiz->title,
                        'round' => $exam->round->name ?? null,
                        'exam' => $exam->title,
                        'test' => $test->title,
                        'type' => $test->type->name ?? null,
                        'questions' => $test->questions_count,
                        'entered' => $entered[$test->id] ?? 0,
                        'submitted' => $counts['submitted'] ?? 0,
                    ];

                    if ($published) {
                        // Rounded where it is read, not where it is stored: one
                        // decimal is what a coordinator can use, and the raw
                        // average carries fifteen.
                        $row['average'] = round((float) $counts['average'], 1);
                    } else {
                        $row['started'] = $counts['started'] ?? 0;
                        $row['duration'] = $test->duration;
                    }

                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Children per test: one row per paper somebody at these venues is expected
     * to sit, counted through the levels the paper targets.
     *
     * 🪤 `COUNT(DISTINCT registrations.id)` and not `COUNT(*)`: the join to
     * `difficulty_level_test` fans a child out once per test their level
     * reaches, and without the DISTINCT a venue with two papers at one level
     * would report twice its roster.
     *
     * @param  list<int>  $schoolIds
     * @return array<int, int>
     */
    private static function enteredPerTest(array $schoolIds, int $seasonId): array
    {
        return DB::table('registrations')
            ->join('difficulty_level_test', 'difficulty_level_test.difficulty_level_id', '=', 'registrations.difficulty_level_id')
            ->where('registrations.season_id', $seasonId)
            ->whereIn('registrations.school_id', $schoolIds)
            ->groupBy('difficulty_level_test.test_id')
            ->select([
                'difficulty_level_test.test_id',
                DB::raw('COUNT(DISTINCT registrations.id) AS entered'),
            ])
            ->get()
            ->keyBy('test_id')
            ->map(fn ($row) => (int) $row->entered)
            ->all();
    }

    /**
     * What the room has actually done, per test.
     *
     * 🪤 Every count is `DISTINCT registration_id`, so a paper sat twice counts
     * one child — which happens after a reset, the one way a second row for the
     * same paper comes to exist. And the average is taken over PUBLISHED
     * attempts alone —
     * `AVG` skips the NULLs the CASE produces, which is exactly the behaviour
     * wanted: unpublished marks are not part of it.
     *
     * @param  list<int>  $schoolIds
     * @return array<int, array<string, float|int>>
     */
    private static function attemptsPerTest(array $schoolIds, int $seasonId): array
    {
        return DB::table('attempts')
            ->join('registrations', 'registrations.id', '=', 'attempts.registration_id')
            ->where('registrations.season_id', $seasonId)
            ->whereIn('registrations.school_id', $schoolIds)
            // The contest side of the one boundary there is.
            ->whereNotIn('attempts.test_id', SampleRound::testIds())
            /*
             * 🔴 A VOIDED attempt is one an administrator took away, with a
             * reason, so that the child could sit again (ADR-0016's reset). It is
             * kept for the audit and it is not something the room did: counted,
             * it would report a paper as handed in that nobody is going to mark.
             * Caught by the test that tried to give one child two attempts — the
             * database refuses two ACTIVE ones, and voiding is how a second one
             * comes to exist at all.
             */
            ->where('attempts.status', '!=', 'void')
            ->groupBy('attempts.test_id')
            ->select([
                'attempts.test_id',
                DB::raw('COUNT(DISTINCT CASE WHEN attempts.started_at IS NOT NULL THEN attempts.registration_id END) AS started'),
                DB::raw('COUNT(DISTINCT CASE WHEN attempts.submitted_at IS NOT NULL THEN attempts.registration_id END) AS submitted'),
                DB::raw('COUNT(CASE WHEN attempts.published_at IS NOT NULL THEN 1 END) AS published'),
                DB::raw('AVG(CASE WHEN attempts.published_at IS NOT NULL THEN attempts.score END) AS average'),
            ])
            ->get()
            ->keyBy('test_id')
            ->map(fn ($row) => [
                'started' => (int) $row->started,
                'submitted' => (int) $row->submitted,
                'published' => (int) $row->published,
                'average' => (float) ($row->average ?? 0),
            ])
            ->all();
    }

    /**
     * The contest's live tree: active quizzes, their active exams, their active
     * tests — in the order the rounds run, which `Quiz::exams()` already
     * enforces for every screen that lists them (ADR-0055).
     *
     * 🪤 Practice is excluded by ROUND and not by quiz type, for the reason
     * {@see SampleRound} gives: a retired practice chain is still practice.
     */
    private static function contestTree(): Collection
    {
        $active = fn (string $table) => fn ($query) => $query->where($table.'.status', 'active');

        return Quiz::query()
            ->where('quizzes.status', 'active')
            ->with([
                'exams' => $active('exams'),
                'exams.round',
                'exams.tests' => fn ($query) => $query
                    ->where('tests.status', 'active')
                    ->whereNotIn('tests.id', SampleRound::testIds())
                    ->withCount('questions'),
                'exams.tests.type',
            ])
            ->orderBy('quizzes.id')
            ->get();
    }
}
