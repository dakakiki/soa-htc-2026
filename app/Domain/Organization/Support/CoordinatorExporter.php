<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Support\XlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the country-coordinators export as a [headers, rows] pair the caller
 * writes with {@see XlsxWriter}. One row per coordinator in the
 * given (already filtered/scoped) set, ordered by name.
 *
 * The column layout mirrors the coordinator import template exactly, so an
 * exported file can be edited and re-imported: identity + geography + venue
 * scope + the four student/results permission flags. Passwords are never
 * exported (import sets a fresh one).
 */
final class CoordinatorExporter
{
    /** @var list<string> */
    public const HEADERS = [
        'Name', 'Email', 'Country', 'Region', 'Venues', 'City', 'Address', 'Phone', 'Status',
        'Can add students', 'Can edit students', 'Can delete students', 'Can reset results',
    ];

    /**
     * @param  list<int>  $userIds
     * @param  Collection<int, int>  $roleIds  the coordinator role ids
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    public static function export(array $userIds, Collection $roleIds, ?int $seasonId): array
    {
        if ($userIds === []) {
            return [self::HEADERS, []];
        }

        $venuesByUser = self::venuesByUser($userIds, $roleIds, $seasonId);

        // Flat query builder rather than Eloquent: a country coordinator can scope
        // hundreds of venues, and hydrating those models cost seconds for a report
        // that only needs their names.
        $users = DB::table('users as u')
            ->leftJoin('countries as c', 'u.country_id', '=', 'c.id')
            ->leftJoin('regions as r', 'u.region_id', '=', 'r.id')
            ->whereIn('u.id', $userIds)
            ->orderBy('u.name')
            ->get([
                'u.id', 'u.name', 'u.email', 'u.city', 'u.address', 'u.phone', 'u.status',
                'u.can_student_insert', 'u.can_student_edit', 'u.can_student_delete',
                'u.can_reset_test_results', 'c.name as country', 'r.name as region',
            ]);

        $yesNo = fn ($flag): string => $flag ? 'Yes' : 'No';

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                $user->name,
                $user->email,
                $user->country,
                $user->region,
                $venuesByUser[(int) $user->id] ?? null,
                $user->city,
                $user->address,
                $user->phone,
                $user->status,
                $yesNo($user->can_student_insert),
                $yesNo($user->can_student_edit),
                $yesNo($user->can_student_delete),
                $yesNo($user->can_reset_test_results),
            ];
        }

        return [self::HEADERS, $rows];
    }

    /**
     * The venue names each coordinator scopes, comma-joined, keyed by user id —
     * one join instead of hydrating every school model.
     *
     * @param  list<int>  $userIds
     * @param  Collection<int, int>  $roleIds
     * @return array<int, string>
     */
    private static function venuesByUser(array $userIds, Collection $roleIds, ?int $seasonId): array
    {
        $links = DB::table('assignment_schools as a')
            ->join('season_user_assignments as sua', 'a.season_user_assignment_id', '=', 'sua.id')
            ->join('schools as s', 'a.school_id', '=', 's.id')
            ->whereIn('sua.user_id', $userIds)
            ->whereIn('sua.role_id', $roleIds)
            ->when($seasonId, fn ($q) => $q->where('sua.season_id', $seasonId))
            ->orderBy('s.name')
            ->get(['sua.user_id', 's.name']);

        $names = [];
        foreach ($links as $link) {
            $names[(int) $link->user_id][(string) $link->name] = true;
        }

        return array_map(fn (array $set): string => implode(', ', array_keys($set)), $names);
    }
}
