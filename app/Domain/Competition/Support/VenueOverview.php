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
    /** The three slices a coordinator's screen can ask for, in reading order. */
    public const SLICES = ['upcoming', 'running', 'published'];

    /**
     * Papers that are OPEN and that nobody at these venues has started.
     *
     * 🔴 This is not a schedule, and it must never be described as one. No exam
     * in this system carries a date: `tests` holds a `duration` and a `status`,
     * `exams` holds a `status`, and the only dates in the database belong to the
     * SEASON. A paper is therefore either active — which is to say visible — or
     * it is not, and the only honest reading of "upcoming" over that data is
     * *open, and this room has not begun it*. If a real timetable is ever wanted
     * it is a column and an administrator's screen, not a rename here.
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
    public static function upcoming(array $schoolIds, int $seasonId): array
    {
        return self::slices($schoolIds, $seasonId)['upcoming'];
    }

    /**
     * Papers this room has begun and that nobody has published yet.
     *
     * 🔴 BOTH halves, and the second one is the half that is easy to drop. A
     * paper that has been sat and then marked is a RESULT; without
     * `! $hasPublished` it would stand here and under {@see published()} at the
     * same time, and a coordinator would be told the same room is both working
     * and finished. Seen on the dev database the day this was written: at one
     * venue all five started papers were already published.
     *
     * It covers two states on purpose, because they are the same question to
     * the person holding the phone — *is my room done with this?* Children may
     * still be sitting, or every paper may be in and waiting on an
     * administrator. Which of the two it is, the screen says in words.
     *
     * @param  list<int>  $schoolIds
     * @return list<array<string, mixed>>
     */
    public static function running(array $schoolIds, int $seasonId): array
    {
        return self::slices($schoolIds, $seasonId)['running'];
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
        return self::slices($schoolIds, $seasonId)['published'];
    }

    /**
     * How many papers each slice holds — what the three ways in are labelled
     * with, for somebody holding one venue.
     *
     * @param  list<int>  $schoolIds
     * @return array<string, int>
     */
    public static function counts(array $schoolIds, int $seasonId): array
    {
        return array_map(count(...), self::slices($schoolIds, $seasonId));
    }

    /**
     * How many papers each venue holds in each slice — the number beside a venue
     * on the screen that asks which one.
     *
     * 🪤 One pair of queries for the whole list rather than a pass per venue. A
     * country coordinator can hold hundreds (875 on the dev database), and
     * {@see slices()} is two queries each; the live tree is instead walked once
     * per venue in PHP, which is eighteen active papers against a list the
     * screen has already capped.
     *
     * No average is computed here. A number beside a venue answers *is there
     * anything to open*, and an average nobody asked for would cost a third
     * query across every venue to say so.
     *
     * @param  list<int>  $schoolIds
     * @return array<int, array<string, int>>
     */
    public static function countsPerSchool(array $schoolIds, int $seasonId): array
    {
        $out = [];

        foreach ($schoolIds as $id) {
            $out[$id] = ['upcoming' => 0, 'running' => 0, 'published' => 0];
        }

        if ($schoolIds === []) {
            return $out;
        }

        $entered = self::enteredPerSchoolTest($schoolIds, $seasonId);
        $attempts = self::attemptsPerSchoolTest($schoolIds, $seasonId);
        $tree = self::contestTree();

        foreach ($schoolIds as $id) {
            $mine = $entered[$id] ?? [];
            $theirs = $attempts[$id] ?? [];

            foreach ($tree as $quiz) {
                foreach ($quiz->exams as $exam) {
                    foreach ($exam->tests as $test) {
                        $counts = $theirs[$test->id] ?? null;

                        if (($counts['published'] ?? 0) > 0) {
                            $out[$id]['published']++;

                            continue;
                        }

                        if (! isset($mine[$test->id])) {
                            continue;
                        }

                        $out[$id][($counts['started'] ?? 0) > 0 ? 'running' : 'upcoming']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * All three slices in one pass over one set of venues.
     *
     * 🪤 Every paper lands in at most ONE of them, and the order of the tests
     * below is the order the application lists them everywhere else (ADR-0055) —
     * the screen does not re-sort, so the three ways in read the same way as the
     * administration does.
     *
     * @param  list<int>  $schoolIds
     * @return array<string, list<array<string, mixed>>>
     */
    private static function slices(array $schoolIds, int $seasonId): array
    {
        $out = ['upcoming' => [], 'running' => [], 'published' => []];

        if ($schoolIds === []) {
            return $out;
        }

        $entered = self::enteredPerTest($schoolIds, $seasonId);
        $attempts = self::attemptsPerTest($schoolIds, $seasonId);

        foreach (self::contestTree() as $quiz) {
            foreach ($quiz->exams as $exam) {
                foreach ($exam->tests as $test) {
                    $counts = $attempts[$test->id] ?? null;
                    $hasPublished = ($counts['published'] ?? 0) > 0;
                    $started = $counts['started'] ?? 0;

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

                    if ($hasPublished) {
                        // Rounded where it is read, not where it is stored: one
                        // decimal is what a coordinator can use, and the raw
                        // average carries fifteen.
                        $row['average'] = round((float) $counts['average'], 1);
                        $out['published'][] = $row;

                        continue;
                    }

                    /*
                     * A test at a level nobody at these venues sits is not this
                     * coordinator's business — which is why both open slices ask
                     * for the paper to be in front of somebody, and the
                     * published one does not: a mark that exists was sat.
                     */
                    if (! isset($entered[$test->id])) {
                        continue;
                    }

                    $row['started'] = $started;
                    $row['duration'] = $test->duration;

                    $out[$started > 0 ? 'running' : 'upcoming'][] = $row;
                }
            }
        }

        return $out;
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
     * The same two questions as above, kept per venue.
     *
     * 🪤 `COUNT(DISTINCT registrations.id)` for the same reason it is used
     * above: the join to `difficulty_level_test` fans a child out once per test
     * their level reaches.
     *
     * @param  list<int>  $schoolIds
     * @return array<int, array<int, int>>
     */
    private static function enteredPerSchoolTest(array $schoolIds, int $seasonId): array
    {
        return DB::table('registrations')
            ->join('difficulty_level_test', 'difficulty_level_test.difficulty_level_id', '=', 'registrations.difficulty_level_id')
            ->where('registrations.season_id', $seasonId)
            ->whereIn('registrations.school_id', $schoolIds)
            ->groupBy('registrations.school_id', 'difficulty_level_test.test_id')
            ->select([
                'registrations.school_id',
                'difficulty_level_test.test_id',
                DB::raw('COUNT(DISTINCT registrations.id) AS entered'),
            ])
            ->get()
            ->groupBy('school_id')
            ->map(fn ($rows) => $rows->keyBy('test_id')->map(fn ($row) => (int) $row->entered)->all())
            ->all();
    }

    /**
     * @param  list<int>  $schoolIds
     * @return array<int, array<int, array<string, int>>>
     */
    private static function attemptsPerSchoolTest(array $schoolIds, int $seasonId): array
    {
        return DB::table('attempts')
            ->join('registrations', 'registrations.id', '=', 'attempts.registration_id')
            ->where('registrations.season_id', $seasonId)
            ->whereIn('registrations.school_id', $schoolIds)
            ->whereNotIn('attempts.test_id', SampleRound::testIds())
            // 🔴 The same voided attempt that is skipped above: administrators
            // take one away with a reason, and it is not something a room did.
            ->where('attempts.status', '!=', 'void')
            ->groupBy('registrations.school_id', 'attempts.test_id')
            ->select([
                'registrations.school_id',
                'attempts.test_id',
                DB::raw('COUNT(DISTINCT CASE WHEN attempts.started_at IS NOT NULL THEN attempts.registration_id END) AS started'),
                DB::raw('COUNT(CASE WHEN attempts.published_at IS NOT NULL THEN 1 END) AS published'),
            ])
            ->get()
            ->groupBy('school_id')
            ->map(fn ($rows) => $rows->keyBy('test_id')->map(fn ($row) => [
                'started' => (int) $row->started,
                'published' => (int) $row->published,
            ])->all())
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
