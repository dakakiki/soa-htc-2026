<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
use App\Domain\Assessment\Support\SampleRound;
use App\Domain\Competition\Models\Attempt;
use App\Domain\Competition\Models\Registration;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Basic competition reporting (CC-12, ADR-0023). Aggregates the current live
 * data — no historical snapshot yet (that arrives with the archive layer, Faza
 * 6). Produces the five headline counts (registered, started, submitted,
 * published, void) plus score statistics, either as a single totals row or
 * broken down by one dimension (group_by).
 *
 * Measure definitions (a clean partition of the attempt rows):
 *  - started   = attempts that are not void (in_progress + completed);
 *  - submitted = completed attempts;
 *  - published = completed attempts with a publication timestamp;
 *  - void      = attempts reset by an admin (5e).
 * `registered` counts registrations in the population scope (season, geography,
 * coordinator, level) and — being registration- not attempt-level — ignores the
 * content filters (quiz/exam/test) and the test type: the same children are
 * registered whichever of the two a report is about. A content row reaches them
 * through the difficulty levels that content is built for.
 */
final class ReportSummary
{
    /** Dimensions that describe the registration population. */
    private const REGISTRATION_DIMS = ['country', 'region', 'school', 'level'];

    /**
     * Dimensions that describe the content. They reach the registrations through
     * the difficulty levels they are built for — see {@see self::registeredRows()}.
     */
    private const CONTENT_DIMS = ['quiz', 'exam', 'test'];

    /**
     * Dimensions the breakdown lists in full, member by member, whether or not a
     * child ever sat them. These are the ones an administrator builds — levels,
     * quizzes, exams, tests — and the empty row is the answer to "did anybody sit
     * this?", which a missing row does not give. Geography is not on the list:
     * sixty-nine countries with rows for the ones that never registered is a
     * longer table, not a better one.
     */
    private const COMPLETE_DIMS = ['level', 'quiz', 'exam', 'test'];

    /** What a report counts unless it is told otherwise: the contest (ADR-0084). */
    public const MODE_DEFAULT = 'competition';

    /** @var list<string> */
    public const MODES = ['competition', 'sample', 'all'];

    /**
     * @param  array<string, mixed>  $filters  normalized: season_id, country_id,
     *                                         region_id, school_id, difficulty_level_id, quiz_id, exam_id, test_id,
     *                                         group_by, and coordinator_school_ids (list<int>|null).
     * @return array<string, mixed>
     */
    public static function build(array $filters): array
    {
        $groupBy = $filters['group_by'] ?? null;

        $totals = self::measures(self::attemptRows($filters, null), self::scoreStats($filters, null))[null] ?? self::emptyMeasures();
        $totals['registered'] = self::registeredRows($filters, null)[null] ?? 0;

        $rows = [];
        if ($groupBy !== null) {
            $measures = self::measures(self::attemptRows($filters, $groupBy), self::scoreStats($filters, $groupBy));
            // Every dimension now has a registered count: geography and level
            // straight off the registration, content through its levels.
            $registered = self::registeredRows($filters, $groupBy);

            /*
             * Members first, in their own order, so the table reads as the list it
             * describes; keys that carry data but are not on that list (an archived
             * quiz with attempts, say) follow rather than vanish. Only the
             * dimensions an administrator builds are listed in full (ADR-0091).
             */
            $members = ! empty($filters['all_members']) && in_array($groupBy, self::COMPLETE_DIMS, true)
                ? self::members($groupBy, $filters)
                : [];

            /*
             * 🪤 A registered count is NOT evidence that this row belongs in this
             * table. Since a content row reaches the registrations through its
             * levels (ADR-0095), every quiz has one — including the practice
             * quizzes, which would then appear in the contest table with a
             * denominator and no competitors, and that is the mixing ADR-0094
             * took out. Content rows are seeded from ATTEMPTS and from the member
             * list; geography and level, whose registered count is the population
             * itself, are seeded from both.
             */
            $dataKeys = in_array($groupBy, self::CONTENT_DIMS, true)
                ? array_keys($measures)
                : array_unique([...array_keys($measures), ...array_keys($registered)]);
            $extra = array_values(array_filter($dataKeys, fn ($k) => ! array_key_exists($k, $members)));

            $keys = [...array_keys($members), ...$extra];
            $described = self::describe($groupBy, $keys);

            foreach ($keys as $key) {
                $row = $measures[$key] ?? self::emptyMeasures();
                $row['key'] = $key;
                $row['label'] = $members[$key]['label'] ?? ($described[$key]['label'] ?? null);
                $row['sublabels'] = $members[$key]['sublabels'] ?? ($described[$key]['sublabels'] ?? []);
                $row['registered'] = $registered[$key] ?? 0;
                $rows[] = $row;
            }

            // Without a member list to follow, the busiest row leads — counted as
            // children, which is what the table shows (ADR-0085).
            if ($members === []) {
                usort($rows, fn ($a, $b) => ($b['participants'] <=> $a['participants']) ?: strcmp((string) $a['label'], (string) $b['label']));
            }
        }

        return [
            'group_by' => $groupBy,
            'totals' => $totals,
            'rows' => $rows,
        ];
    }

    /**
     * A two-dimension cross-tab of average score (the heatmap): completed, scored
     * attempts grouped by rowBy × colBy. Each cell carries the average and the
     * count; min/max bound the colour scale. Absent combinations simply have no
     * cell. Reuses the same population/content scope as the rest of the report.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function matrix(array $filters, string $rowBy, string $colBy): array
    {
        $needExam = in_array('exam', [$rowBy, $colBy], true);
        $rowCol = self::groupColumn($rowBy);
        $colCol = self::groupColumn($colBy);

        $rows = self::attemptBase($filters, $needExam ? 'exam' : null)
            ->where('attempts.status', 'completed')
            ->whereNotNull('attempts.score')
            ->select([
                DB::raw("$rowCol as rkey"),
                DB::raw("$colCol as ckey"),
                DB::raw('avg(attempts.score) as avg_score'),
                DB::raw('count(*) as n'),
            ])
            ->groupBy(DB::raw($rowCol), DB::raw($colCol))
            ->get();

        $cells = [];
        $rowKeys = [];
        $colKeys = [];
        $min = null;
        $max = null;
        foreach ($rows as $row) {
            $avg = round((float) $row->avg_score, 2);
            $rowKeys[(int) $row->rkey] = true;
            $colKeys[(int) $row->ckey] = true;
            $cells[] = ['row_key' => (int) $row->rkey, 'col_key' => (int) $row->ckey, 'avg' => $avg, 'count' => (int) $row->n];
            $min = $min === null ? $avg : min($min, $avg);
            $max = $max === null ? $avg : max($max, $avg);
        }

        return [
            'row_by' => $rowBy,
            'col_by' => $colBy,
            'rows' => self::axis($rowBy, array_keys($rowKeys), $filters),
            'cols' => self::axis($colBy, array_keys($colKeys), $filters),
            'cells' => $cells,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * One side of the heatmap: its members, and what each is called.
     *
     * 🔴 A level with no scores is a column, not an absence. The grid used to be
     * built from the data alone, so a difficulty level nobody sat simply was not
     * there — and a reader comparing levels cannot see the gap that is not drawn.
     * Levels, quizzes, exams and tests are things an administrator built, so all
     * of them stand on the axis, in the order they are taught or built, and the
     * ones with data follow. Geography keeps the old behaviour: the countries in
     * the data, alphabetically.
     *
     * 🪤 The labels carry their parent (a level its category), because
     * `level_short` repeats across categories — `BH` is two different columns
     * here, and without the category they are the same column twice (ADR-0088).
     *
     * @param  list<int>  $keys  members that actually carry scores
     * @param  array<string, mixed>  $filters
     * @return list<array{key: int, label: string|null, sublabels: list<string>}>
     */
    private static function axis(string $dim, array $keys, array $filters): array
    {
        $members = in_array($dim, self::COMPLETE_DIMS, true) ? self::members($dim, $filters) : [];
        $extra = array_values(array_filter($keys, fn ($k) => ! array_key_exists($k, $members)));
        $described = self::describe($dim, $extra);

        $tail = array_map(fn ($k) => [
            'key' => $k,
            'label' => $described[$k]['label'] ?? null,
            'sublabels' => $described[$k]['sublabels'] ?? [],
        ], $extra);
        usort($tail, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        $head = [];
        foreach ($members as $key => $member) {
            $head[] = ['key' => $key, 'label' => $member['label'], 'sublabels' => $member['sublabels']];
        }

        return [...$head, ...$tail];
    }

    /**
     * Attempt count rows keyed by group value (or the single key null when not
     * grouping).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string|null, array{participants: int, started: int, submitted: int, published: int, void: int}>
     */
    private static function attemptRows(array $filters, ?string $groupBy): array
    {
        $query = self::attemptBase($filters, $groupBy);

        $select = [
            /*
             * 🔴 Competitors, not attempts — and the difference is the whole
             * reason this line exists. «Participation» divided `started` by
             * `registered`, which is attempts over children: 145.713 over
             * 108.812 read **133,9 %**, a participation rate above everybody.
             * Counted as people, 61.309 of 108.812 children ever started, which
             * is **56,3 %** — the number the label was always promising, and a
             * very different thing to tell a client (ADR-0085).
             *
             * `started` stays as it is. Attempts per child (2,38 in the contest,
             * because a child sits several tests) is a real measure too; it just
             * is not a rate of anything.
             */
            DB::raw("count(distinct case when attempts.status <> 'void' then attempts.registration_id end) as participants"),
            /*
             * The same question asked of the two stages after it: how many
             * CHILDREN got that far, not how many attempts did. A breakdown row
             * that puts children beside attempts invites the subtraction nobody
             * should make — 61.318 started and 145.780 submitted reads like the
             * contest gained people halfway through.
             */
            DB::raw("count(distinct case when attempts.status = 'completed' then attempts.registration_id end) as submitted_participants"),
            DB::raw("count(distinct case when attempts.status = 'completed' and attempts.published_at is not null then attempts.registration_id end) as published_participants"),
            DB::raw("sum(case when attempts.status <> 'void' then 1 else 0 end) as started"),
            DB::raw("sum(case when attempts.status = 'completed' then 1 else 0 end) as submitted"),
            DB::raw("sum(case when attempts.status = 'completed' and attempts.published_at is not null then 1 else 0 end) as published"),
            DB::raw("sum(case when attempts.status = 'void' then 1 else 0 end) as void_count"),
        ];

        $rows = self::applyGroup($query, $groupBy, $select)->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$groupBy === null ? null : $row->gkey] = [
                'participants' => (int) $row->participants,
                'submitted_participants' => (int) $row->submitted_participants,
                'published_participants' => (int) $row->published_participants,
                'started' => (int) $row->started,
                'submitted' => (int) $row->submitted,
                'published' => (int) $row->published,
                'void' => (int) $row->void_count,
            ];
        }

        return $out;
    }

    /**
     * Score statistics for submitted attempts, computed **in the database**.
     *
     * 🪤 This used to select every score into PHP and sort the array to find a
     * median — 184,384 floats on the current population, about 4.9 seconds of the
     * report's 7.9. Count, average, minimum and maximum are what SQL is for, and
     * the median is one window pass: `row_number()` over the ordered scores, then
     * the middle row, or the mean of the middle two when the count is even.
     *
     * The window functions are available on MySQL 8 and MariaDB 10.2 upwards, so
     * this does not tie the report to one engine.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string|null, array{count: int, avg: float|null, min: float|null, max: float|null, median: float|null}>
     */
    private static function scoreStats(array $filters, ?string $groupBy): array
    {
        $query = self::attemptBase($filters, $groupBy)
            ->where('attempts.status', 'completed')
            ->whereNotNull('attempts.score');

        $over = $groupBy === null ? '' : 'partition by '.self::groupColumn($groupBy).' ';

        $select = [
            DB::raw('attempts.score as score'),
            DB::raw("row_number() over ({$over}order by attempts.score) as rn"),
            DB::raw("count(*) over ({$over}) as n"),
        ];

        if ($groupBy !== null) {
            $select[] = DB::raw(self::groupColumn($groupBy).' as gkey');
        }

        $outer = DB::query()->fromSub($query->select($select)->getQuery(), 'x')->select([
            DB::raw('count(*) as cnt'),
            DB::raw('avg(score) as avg_score'),
            DB::raw('min(score) as min_score'),
            DB::raw('max(score) as max_score'),
            /*
             * The middle row, or both middle rows when the count is even; `avg`
             * over the one or two that survive is the median either way.
             *
             * 🪤 Written as two comparisons on purpose, and both engines had a
             * say in the spelling. The obvious version uses `floor`, which SQLite
             * does not have. The next one, `abs(rn * 2 - n - 1) <= 1`, passed on
             * SQLite and failed on MySQL: `row_number()` there is BIGINT
             * UNSIGNED, so the subtraction underflows for every row in the lower
             * half. Comparing without subtracting is the one form both accept.
             */
            DB::raw('avg(case when rn * 2 >= n and rn * 2 <= n + 2 then score end) as median_score'),
        ]);

        if ($groupBy !== null) {
            $outer->addSelect('gkey')->groupBy('gkey');
        }

        $out = [];
        foreach ($outer->get() as $row) {
            $out[$groupBy === null ? null : $row->gkey] = [
                'count' => (int) $row->cnt,
                'avg' => round((float) $row->avg_score, 2),
                'min' => (float) $row->min_score,
                'max' => (float) $row->max_score,
                'median' => round((float) $row->median_score, 2),
            ];
        }

        return $out;
    }

    /**
     * Registered counts keyed by group value. Applies only the population filters,
     * never the content ones.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string|null, int>
     */
    private static function registeredRows(array $filters, ?string $groupBy): array
    {
        $query = Registration::query()
            ->from('registrations as r')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id');

        self::applyPopulationFilters($query, $filters, registrationTable: 'r');

        /*
         * A quiz, an exam and a test each carry the difficulty levels they are
         * for, and a registration carries the level the child sits at — so the
         * registered column for a content row is the children **registered at
         * that content's levels**: how many could sit it, against how many did.
         *
         * 🔴 It used to be a dash there, on the grounds that a registration is
         * not attached to a test. True, but it leaves the one column that does
         * not change between the contest and practice empty in half the tables —
         * and the reader has no denominator at all (owner, 14.09).
         *
         * 🪤 `count(distinct r.id)`, because content for several levels joins the
         * same registration once per level.
         */
        if (in_array($groupBy, self::CONTENT_DIMS, true)) {
            [$pivot, $column] = match ($groupBy) {
                'quiz' => ['difficulty_level_quiz', 'quiz_id'],
                'exam' => ['difficulty_level_exam', 'exam_id'],
                default => ['difficulty_level_test', 'test_id'],
            };

            $rows = $query
                ->join($pivot.' as p', 'p.difficulty_level_id', '=', 'r.difficulty_level_id')
                ->select([DB::raw("p.$column as gkey"), DB::raw('count(distinct r.id) as registered')])
                ->groupBy(DB::raw("p.$column"))
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[$row->gkey] = (int) $row->registered;
            }

            return $out;
        }

        // Only registration dimensions can group the population; for content
        // group_by we still return the overall total under the null key.
        $regGroup = ($groupBy !== null && in_array($groupBy, self::REGISTRATION_DIMS, true)) ? $groupBy : null;

        $query = self::applyGroup($query, $regGroup, [DB::raw('count(*) as registered')]);
        $rows = $query->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$regGroup === null ? null : $row->gkey] = (int) $row->registered;
        }

        return $out;
    }

    /**
     * Attempts joined to their registration (needed for the population filters and
     * dimensions), with a left join to schools so registrations that sat at an
     * external school (null school_id) are still counted.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Attempt>
     */
    private static function attemptBase(array $filters, ?string $groupBy)
    {
        $query = Attempt::query();

        /*
         * 🪤 The registration and school joins are made only when something
         * actually reads them. They used to be unconditional, and on the whole
         * population that cost 1.6 seconds per query for nothing: an attempt
         * always has a registration, so joining it without filtering or grouping
         * on it changes no row and answers no question.
         */
        if (self::needsRegistrations($filters, $groupBy)) {
            $query->join('registrations as r', 'attempts.registration_id', '=', 'r.id');

            if (self::needsSchools($filters, $groupBy)) {
                $query->leftJoin('schools as s', 'r.school_id', '=', 's.id');
            }
        }

        if ($groupBy === 'exam' || ! empty($filters['exam_id'])) {
            $query->join('exam_test as et', 'attempts.test_id', '=', 'et.test_id');
        }

        self::applyPopulationFilters($query, $filters, registrationTable: 'r');
        self::applyContentFilters($query, $filters);
        self::applyMode($query, $filters);

        return $query;
    }

    /**
     * Which contest this report is about — and by default it is THE contest.
     *
     * 🔴 Until 2026-09-14 there was no such choice and every count was both at
     * once. Measured on the real population: of 184.389 submitted attempts,
     * 38.676 — **one in five** — were practice. Worse for the publication rate,
     * because a practice mark publishes ITSELF (ADR-0019), so its rate is 100%
     * by construction: the headline read 144.769 of 184.389 published (78,5%),
     * while the contest's own rate was 106.093 of 145.713 (72,8%). Nearly six
     * points of flattery on the one number that is supposed to measure how far
     * an administrator has got.
     *
     * Three reasons they are not one population: different people sit them,
     * publication behaves differently, and practice REPEATS while the contest is
     * one attempt (ADR-0016). A sum over the two answers no question anybody has.
     *
     * 🪤 The boundary is {@see SampleRound} and nothing written here: this used
     * to carry its own copy of the join, which dropped `exams.status = active`
     * and so disagreed with the ledger in a case no data has reached yet.
     */
    private static function applyMode($query, array $filters): void
    {
        $mode = $filters['mode'] ?? self::MODE_DEFAULT;

        if ($mode === 'all') {
            return;
        }

        $mode === 'sample'
            ? $query->whereIn('attempts.test_id', SampleRound::testIds())
            : $query->whereNotIn('attempts.test_id', SampleRound::testIds());
    }

    /** Filters and groupings that read a column on `registrations` (or on `schools`, which hangs off it). */
    private static function needsRegistrations(array $filters, ?string $groupBy): bool
    {
        if (in_array($groupBy, self::REGISTRATION_DIMS, true)) {
            return true;
        }

        foreach (['season_id', 'country_id', 'region_id', 'school_id', 'difficulty_level_id'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return array_key_exists('coordinator_school_ids', $filters) && is_array($filters['coordinator_school_ids']);
    }

    /** Only the region lives on `schools`; everything else is on the registration. */
    private static function needsSchools(array $filters, ?string $groupBy): bool
    {
        return $groupBy === 'region' || ! empty($filters['region_id']);
    }

    /**
     * Season / geography / coordinator / level filters. Works for both the
     * attempt query (registration aliased `r`) and the registered query (base
     * `registrations` table).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  array<string, mixed>  $filters
     */
    private static function applyPopulationFilters($query, array $filters, string $registrationTable = 'registrations'): void
    {
        $r = $registrationTable;

        $query
            ->when($filters['season_id'] ?? null, fn ($q, $v) => $q->where("$r.season_id", $v))
            ->when($filters['country_id'] ?? null, fn ($q, $v) => $q->where("$r.country_id", $v))
            ->when($filters['region_id'] ?? null, fn ($q, $v) => $q->where('s.region_id', $v))
            ->when($filters['school_id'] ?? null, fn ($q, $v) => $q->where("$r.school_id", $v))
            ->when($filters['difficulty_level_id'] ?? null, fn ($q, $v) => $q->where("$r.difficulty_level_id", $v));

        // A coordinator filter narrows to that coordinator's schools; an empty
        // set (coordinator with no schools) yields no rows.
        if (array_key_exists('coordinator_school_ids', $filters) && is_array($filters['coordinator_school_ids'])) {
            $query->whereIn("$r.school_id", $filters['coordinator_school_ids']);
        }
    }

    /**
     * @param  Builder<Attempt>  $query
     * @param  array<string, mixed>  $filters
     */
    private static function applyContentFilters($query, array $filters): void
    {
        $query
            ->when($filters['quiz_id'] ?? null, fn ($q, $v) => $q->where('attempts.quiz_id', $v))
            ->when($filters['test_id'] ?? null, fn ($q, $v) => $q->where('attempts.test_id', $v))
            ->when($filters['exam_id'] ?? null, fn ($q, $v) => $q->where('et.exam_id', $v));
    }

    /**
     * Add the group column + aggregation, or (aggregate:false) just the group
     * column alongside a raw select. The group column is exposed as `gkey`.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder<*>
     *
     * @param  TQuery  $query
     * @param  list<Expression>  $select
     * @return TQuery
     */
    private static function applyGroup($query, ?string $groupBy, array $select, bool $aggregate = true)
    {
        if ($groupBy === null) {
            return $query->select($select);
        }

        $column = self::groupColumn($groupBy);
        $query->select([DB::raw("$column as gkey"), ...$select]);

        if ($aggregate) {
            $query->groupBy(DB::raw($column));
        }

        return $query;
    }

    private static function groupColumn(string $groupBy): string
    {
        return match ($groupBy) {
            'country' => 'r.country_id',
            'region' => 's.region_id',
            'school' => 'r.school_id',
            'level' => 'r.difficulty_level_id',
            'quiz' => 'attempts.quiz_id',
            'exam' => 'et.exam_id',
            'test' => 'attempts.test_id',
            default => 'r.country_id',
        };
    }

    /**
     * Turn each group's raw scores into avg/min/max/median/count.
     *
     * @param  array<int|string|null, array{participants: int, started: int, submitted: int, published: int, void: int}>  $counts
     * @param  array<int|string|null, list<float>>  $scores
     * @return array<int|string|null, array<string, mixed>>
     */
    private static function measures(array $counts, array $scores): array
    {
        $out = [];
        foreach ($counts as $key => $c) {
            $out[$key] = $c + ['score' => $scores[$key] ?? self::emptyStats()];
        }

        return $out;
    }

    /** What a group with no scored attempt reports. */
    private static function emptyStats(): array
    {
        return ['count' => 0, 'avg' => null, 'min' => null, 'max' => null, 'median' => null];
    }

    private static function emptyMeasures(): array
    {
        return [
            'participants' => 0, 'submitted_participants' => 0, 'published_participants' => 0,
            'started' => 0, 'submitted' => 0, 'published' => 0, 'void' => 0, 'score' => self::emptyStats(),
        ];
    }

    /**
     * Resolve group ids to human labels for the given dimension.
     *
     * @param  list<int|string|null>  $keys
     * @return array<int|string, string>
     */
    private static function labels(string $groupBy, array $keys): array
    {
        return array_map(fn (array $d) => (string) $d['label'], self::describe($groupBy, $keys));
    }

    /**
     * Label plus the lines that go under it.
     *
     * 🔴 A name is not always an identification. "Region 2" is the name of a
     * region in four different countries, `level_short` repeats across difficulty
     * categories by design (ADR-0088), and two venues share a name as soon as two
     * countries both have a "Gymnasium 1". A table listing them without saying
     * where they belong is not untidy, it is unreadable: the reader cannot tell
     * which row is theirs. So each of those carries its parent underneath, and a
     * test — which hangs off an exam, which hangs off a quiz — carries both.
     *
     * @param  list<int|string|null>  $keys
     * @return array<int|string, array{label: string|null, sublabels: list<string>}>
     */
    private static function describe(string $dim, array $keys): array
    {
        $ids = array_values(array_filter($keys, fn ($k) => $k !== null));
        if ($ids === []) {
            return [];
        }

        $out = [];

        if ($dim === 'region') {
            $rows = Region::query()->leftJoin('countries', 'countries.id', '=', 'regions.country_id')
                ->whereIn('regions.id', $ids)
                ->get(['regions.id as id', 'regions.name as name', 'countries.name as parent']);
        } elseif ($dim === 'school') {
            $rows = School::query()->leftJoin('countries', 'countries.id', '=', 'schools.country_id')
                ->whereIn('schools.id', $ids)
                ->get(['schools.id as id', 'schools.name as name', 'countries.name as parent']);
        } elseif ($dim === 'level') {
            $rows = DifficultyLevel::query()
                ->leftJoin('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
                ->whereIn('difficulty_levels.id', $ids)
                ->get(['difficulty_levels.id as id', 'difficulty_levels.level_short as name', 'difficulty_categories.name as parent']);
        } else {
            [$model, $labelColumn] = match ($dim) {
                'country' => [Country::class, 'name'],
                'quiz' => [Quiz::class, 'title'],
                'exam' => [Exam::class, 'title'],
                'test' => [Test::class, 'title'],
                default => [Country::class, 'name'],
            };

            /** @var QueryBuilder $q */
            $rows = $model::query()->whereIn('id', $ids)->get(['id', $labelColumn.' as name']);
        }

        foreach ($rows as $row) {
            $parent = $row->parent ?? null;
            $out[(int) $row->id] = [
                'label' => $row->name,
                'sublabels' => $parent === null || $parent === '' ? [] : [(string) $parent],
            ];
        }

        // An exam belongs to its quiz, a test to both — and the pivots are
        // many-to-many, so the titles are grouped here rather than concatenated in
        // SQL (`group_concat` takes a separator on SQLite and a SEPARATOR keyword
        // on MySQL; the two spellings do not meet).
        if ($dim === 'exam' || $dim === 'test') {
            foreach (self::ancestry($dim, $ids) as $id => $lines) {
                if (isset($out[$id])) {
                    $out[$id]['sublabels'] = $lines;
                }
            }
        }

        return $out;
    }

    /**
     * "Quiz: …" for an exam; "Quiz: …" and "Exam: …" for a test.
     *
     * @param  list<int|string>  $ids
     * @return array<int, list<string>>
     */
    private static function ancestry(string $dim, array $ids): array
    {
        $quizzes = [];
        $exams = [];

        if ($dim === 'exam') {
            $rows = DB::table('exam_quiz')
                ->join('quizzes', 'quizzes.id', '=', 'exam_quiz.quiz_id')
                ->whereIn('exam_quiz.exam_id', $ids)
                ->get(['exam_quiz.exam_id as id', 'quizzes.title as quiz_title']);
        } else {
            $rows = DB::table('exam_test')
                ->join('exams', 'exams.id', '=', 'exam_test.exam_id')
                ->leftJoin('exam_quiz', 'exam_quiz.exam_id', '=', 'exams.id')
                ->leftJoin('quizzes', 'quizzes.id', '=', 'exam_quiz.quiz_id')
                ->whereIn('exam_test.test_id', $ids)
                ->get(['exam_test.test_id as id', 'exams.title as exam_title', 'quizzes.title as quiz_title']);
        }

        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (! empty($row->quiz_title)) {
                $quizzes[$id][$row->quiz_title] = true;
            }
            if (! empty($row->exam_title)) {
                $exams[$id][$row->exam_title] = true;
            }
        }

        $out = [];
        foreach (array_unique([...array_keys($quizzes), ...array_keys($exams)]) as $id) {
            $lines = [];
            if (isset($quizzes[$id])) {
                $lines[] = 'Quiz: '.implode(', ', array_keys($quizzes[$id]));
            }
            if (isset($exams[$id])) {
                $lines[] = 'Exam: '.implode(', ', array_keys($exams[$id]));
            }
            $out[$id] = $lines;
        }

        return $out;
    }

    /**
     * Every member of a dimension, in the order it is taught or built rather than
     * the order the data happens to fall in.
     *
     * 🪤 Levels go by category and position, never alphabetically — `LH` sorts
     * after `H5` and before `H1` in a list nobody reads that way (ADR-0089).
     * Exams and tests follow the quiz/exam the filters already narrow to, so the
     * table lists what the rest of the screen is talking about.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string|null, sublabels: list<string>}>
     */
    private static function members(string $dim, array $filters): array
    {
        /*
         * 🔴 No mixing (ADR-0094): a contest report lists contest quizzes, exams
         * and tests, a practice report lists practice ones. The rows used to be
         * every active quiz whatever the report was about, so a contest table
         * carried six practice rows that could only ever be empty — and an empty
         * row is supposed to mean "nobody sat it", not "wrong population".
         * Difficulty levels are not of either type and are always listed in full.
         */
        $mode = $filters['mode'] ?? self::MODE_DEFAULT;
        $ofType = fn ($query, string $dimension) => $query->when(
            $mode !== 'all',
            fn ($q) => $q->whereIn($dimension === 'quiz' ? 'quizzes.id' : ($dimension === 'exam' ? 'exams.id' : 'tests.id'),
                SampleRound::idsOfType($dimension, $mode))
        );

        $ids = match ($dim) {
            'level' => DifficultyLevel::query()
                ->leftJoin('difficulty_categories', 'difficulty_categories.id', '=', 'difficulty_levels.difficulty_category_id')
                ->orderBy('difficulty_categories.type')->orderBy('difficulty_categories.id')->orderBy('difficulty_levels.position')
                ->pluck('difficulty_levels.id')->all(),
            'quiz' => $ofType(Quiz::query()->where('quizzes.status', 'active'), 'quiz')
                ->orderBy('title')->pluck('quizzes.id')->all(),
            'exam' => $ofType(Exam::query()->where('exams.status', 'active'), 'exam')
                ->when($filters['quiz_id'] ?? null, fn ($q, $v) => $q->whereIn(
                    'exams.id',
                    DB::table('exam_quiz')->where('quiz_id', $v)->select('exam_id')
                ))
                ->orderBy('title')->pluck('exams.id')->all(),
            'test' => $ofType(Test::query()->where('tests.status', 'active'), 'test')
                ->when($filters['exam_id'] ?? null, fn ($q, $v) => $q->whereIn(
                    'tests.id',
                    DB::table('exam_test')->where('exam_id', $v)->select('test_id')
                ))
                ->when(empty($filters['exam_id']) && ! empty($filters['quiz_id']), fn ($q) => $q->whereIn(
                    'tests.id',
                    DB::table('exam_test')
                        ->join('exam_quiz', 'exam_quiz.exam_id', '=', 'exam_test.exam_id')
                        ->where('exam_quiz.quiz_id', $filters['quiz_id'])
                        ->select('exam_test.test_id')
                ))
                ->orderBy('title')->pluck('tests.id')->all(),
            default => [],
        };

        $described = self::describe($dim, $ids);

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = $described[(int) $id] ?? ['label' => null, 'sublabels' => []];
        }

        return $out;
    }
}
