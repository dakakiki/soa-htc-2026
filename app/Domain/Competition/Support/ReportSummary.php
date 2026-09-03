<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\Test;
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
 * content filters (quiz/exam/test) and is null for content group_by rows.
 */
final class ReportSummary
{
    /** Dimensions that describe the registration population. */
    private const REGISTRATION_DIMS = ['country', 'region', 'school', 'level'];

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
            $registered = in_array($groupBy, self::REGISTRATION_DIMS, true)
                ? self::registeredRows($filters, $groupBy)
                : [];

            $keys = array_unique([...array_keys($measures), ...array_keys($registered)]);
            $labels = self::labels($groupBy, $keys);

            foreach ($keys as $key) {
                $row = $measures[$key] ?? self::emptyMeasures();
                $row['key'] = $key;
                $row['label'] = $labels[$key] ?? null;
                $row['registered'] = in_array($groupBy, self::REGISTRATION_DIMS, true)
                    ? ($registered[$key] ?? 0)
                    : null;
                $rows[] = $row;
            }

            usort($rows, fn ($a, $b) => ($b['submitted'] <=> $a['submitted']) ?: strcmp((string) $a['label'], (string) $b['label']));
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

        $rowKeyList = array_keys($rowKeys);
        $colKeyList = array_keys($colKeys);
        $rowLabels = self::labels($rowBy, $rowKeyList);
        $colLabels = self::labels($colBy, $colKeyList);

        $axis = function (array $keys, array $labels): array {
            $out = array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k] ?? null], $keys);
            usort($out, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

            return $out;
        };

        return [
            'row_by' => $rowBy,
            'col_by' => $colBy,
            'rows' => $axis($rowKeyList, $rowLabels),
            'cols' => $axis($colKeyList, $colLabels),
            'cells' => $cells,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Attempt count rows keyed by group value (or the single key null when not
     * grouping).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string|null, array{started: int, submitted: int, published: int, void: int}>
     */
    private static function attemptRows(array $filters, ?string $groupBy): array
    {
        $query = self::attemptBase($filters, $groupBy);

        $select = [
            DB::raw("sum(case when attempts.status <> 'void' then 1 else 0 end) as started"),
            DB::raw("sum(case when attempts.status = 'completed' then 1 else 0 end) as submitted"),
            DB::raw("sum(case when attempts.status = 'completed' and attempts.published_at is not null then 1 else 0 end) as published"),
            DB::raw("sum(case when attempts.status = 'void' then 1 else 0 end) as void_count"),
        ];

        $rows = self::applyGroup($query, $groupBy, $select)->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$groupBy === null ? null : $row->gkey] = [
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

        return $query;
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
     * @param  array<int|string|null, array{started: int, submitted: int, published: int, void: int}>  $counts
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
        return ['started' => 0, 'submitted' => 0, 'published' => 0, 'void' => 0, 'score' => self::emptyStats()];
    }

    /**
     * Resolve group ids to human labels for the given dimension.
     *
     * @param  list<int|string|null>  $keys
     * @return array<int|string, string>
     */
    private static function labels(string $groupBy, array $keys): array
    {
        $ids = array_values(array_filter($keys, fn ($k) => $k !== null));
        if ($ids === []) {
            return [];
        }

        [$model, $labelColumn] = match ($groupBy) {
            'country' => [Country::class, 'name'],
            'region' => [Region::class, 'name'],
            'school' => [School::class, 'name'],
            'level' => [DifficultyLevel::class, 'level_short'],
            'quiz' => [Quiz::class, 'title'],
            'exam' => [Exam::class, 'title'],
            'test' => [Test::class, 'title'],
            default => [Country::class, 'name'],
        };

        /** @var QueryBuilder $q */
        return $model::query()->whereIn('id', $ids)->pluck($labelColumn, 'id')->all();
    }
}
