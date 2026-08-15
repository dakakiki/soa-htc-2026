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

        $totals = self::measures(self::attemptRows($filters, null), self::scoreRows($filters, null))[null] ?? self::emptyMeasures();
        $totals['registered'] = self::registeredRows($filters, null)[null] ?? 0;

        $rows = [];
        if ($groupBy !== null) {
            $measures = self::measures(self::attemptRows($filters, $groupBy), self::scoreRows($filters, $groupBy));
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
     * Raw (group, score) pairs for submitted (completed) attempts, grouped in PHP
     * so median is feasible alongside avg/min/max.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string|null, list<float>>
     */
    private static function scoreRows(array $filters, ?string $groupBy): array
    {
        $query = self::attemptBase($filters, $groupBy)
            ->where('attempts.status', 'completed');

        $select = [DB::raw('attempts.score as score')];
        $rows = self::applyGroup($query, $groupBy, $select, aggregate: false)->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $groupBy === null ? null : $row->gkey;
            $out[$key][] = (float) $row->score;
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
        $query = Attempt::query()
            ->join('registrations as r', 'attempts.registration_id', '=', 'r.id')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id');

        if ($groupBy === 'exam' || ! empty($filters['exam_id'])) {
            $query->join('exam_test as et', 'attempts.test_id', '=', 'et.test_id');
        }

        self::applyPopulationFilters($query, $filters, registrationTable: 'r');
        self::applyContentFilters($query, $filters);

        return $query;
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
            $out[$key] = $c + ['score' => self::stats($scores[$key] ?? [])];
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     * @return array{count: int, avg: float|null, min: float|null, max: float|null, median: float|null}
     */
    private static function stats(array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'avg' => null, 'min' => null, 'max' => null, 'median' => null];
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        $median = $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        return [
            'count' => $n,
            'avg' => round(array_sum($values) / $n, 2),
            'min' => $values[0],
            'max' => $values[$n - 1],
            'median' => round($median, 2),
        ];
    }

    /**
     * @return array{started: int, submitted: int, published: int, void: int, score: array<string, mixed>}
     */
    private static function emptyMeasures(): array
    {
        return ['started' => 0, 'submitted' => 0, 'published' => 0, 'void' => 0, 'score' => self::stats([])];
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
