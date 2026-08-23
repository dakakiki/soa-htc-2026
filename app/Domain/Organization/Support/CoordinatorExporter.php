<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Support\Collection;

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

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with([
                'country',
                'region',
                'seasonAssignments' => function ($query) use ($roleIds, $seasonId): void {
                    $query->whereIn('role_id', $roleIds)
                        ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
                        ->with('schools');
                },
            ])
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($users as $user) {
            $venues = $user->seasonAssignments
                ->flatMap(fn ($assignment) => $assignment->schools)
                ->pluck('name')
                ->unique()
                ->implode(', ');

            $rows[] = [
                $user->name,
                $user->email,
                $user->country?->name,
                $user->region?->name,
                $venues !== '' ? $venues : null,
                $user->city,
                $user->address,
                $user->phone,
                $user->status,
                $user->can_student_insert ? 'Yes' : 'No',
                $user->can_student_edit ? 'Yes' : 'No',
                $user->can_student_delete ? 'Yes' : 'No',
                $user->can_reset_test_results ? 'Yes' : 'No',
            ];
        }

        return [self::HEADERS, $rows];
    }
}
