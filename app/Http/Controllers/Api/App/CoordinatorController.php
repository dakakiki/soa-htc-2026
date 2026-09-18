<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Domain\Competition\Support\VenueOverview;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the installed application shows a coordinator: the papers open across
 * the venues they run, and the numbers of any venue they hold.
 *
 * 🔴 Every answer here is bounded by {@see User::allowedSchoolIds()}, which is
 * the application's one row-scope authority — `null` for somebody holding
 * `schools.view.all`, otherwise the venues bound to their assignments in the
 * ACTIVE season. Nothing on these routes accepts a venue from the client
 * without checking it against that set: a coordinator asking for venue 7 by
 * hand gets a 404, not another venue's room.
 *
 * 🪤 A 404 and never a 403. The two are different sentences to somebody probing:
 * "you may not see this venue" confirms the venue exists. The same reasoning as
 * PR #41, which stopped the API naming its own classes.
 *
 * 🔴 The EXAM PASSWORD is not served here, cannot be, and is not going to be.
 * `quizzes.quiz_password` is a bcrypt hash: the server can check one and cannot
 * read one back. Told the owner on 2026-09-15 along with the one way it could be
 * done — storing it reversibly — and the decision was to DROP THE CARD instead
 * ("skloni karticu sa lozinkom"). So the hash stays a hash, and a coordinator
 * gets the password the way they do today, from an administrator.
 */
class CoordinatorController extends Controller
{
    /**
     * The Welcome screen: who this is, what is open, and what has been
     * published.
     *
     * 🪤 The round comes from the SEASON — an administrator typed it with the
     * season record (owner, 2026-09-15: "ovaj podatak uzimas iz settings") — and
     * not from counting anything. ADR-0081 is the whole reason: a round inferred
     * from what happens to be active was wrong for about half the countries
     * reading it.
     */
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        $season = SeasonContext::active();
        $schoolIds = $this->schoolIds($user);

        return response()->json(['data' => [
            'name' => $user->name,
            'role' => $this->roleLabel($user),
            // Where they stand: one venue names itself, several are counted.
            'venue' => $this->soleVenue($user, $schoolIds),
            'venues_count' => count($schoolIds),
            'round' => $season?->round_number,
            'season' => $season?->name,
            /*
             * 🔴 Counted only for somebody who holds ONE venue, and `null`
             * otherwise. A number beside the three ways in has to be a number
             * about a room; summed across the two dozen venues a country
             * coordinator runs it is about no room at all, and putting it there
             * is what made the old screen unreadable in the first place. They
             * are asked which venue, and the numbers begin after that.
             */
            'counts' => $season !== null && count($schoolIds) === 1
                ? VenueOverview::counts($schoolIds, $season->id)
                : null,
        ]]);
    }

    /**
     * The venues this coordinator may open, for the screen that asks which one.
     *
     * A school coordinator holds exactly one and never sees this screen; the
     * search is for a country coordinator with two dozen of them, and it looks
     * at the name and the city because those are the two things written on the
     * outside of a school.
     */
    public function venues(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $slice = $this->slice($request);
        $season = SeasonContext::active();

        $venues = School::query()
            ->whereIn('id', $this->schoolIds($request->user()))
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('city', 'like', '%'.$term.'%')))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'city']);

        /*
         * The count that decides whether a row is worth opening, and it is the
         * count for the way in they tapped — a venue with nothing upcoming may
         * well have results. Counted over the venues actually listed, so a
         * search narrows the work as well as the list.
         */
        $counts = $season === null
            ? []
            : VenueOverview::countsPerSchool($venues->pluck('id')->map(intval(...))->all(), $season->id);

        return response()->json(['data' => $venues->map(fn (School $school) => [
            'id' => $school->id,
            'name' => $school->name,
            'city' => $school->city,
            'papers' => $counts[$school->id][$slice] ?? 0,
        ])]);
    }

    /**
     * One venue's papers in one of the three slices, paper by paper.
     *
     * 🔴 An AVERAGE reaches only the published slice. While a room is still
     * working, an average says something different every time it is read (owner,
     * 2026-09-15) — that is not a number, it is a number in motion.
     *
     * 🪤 No "sitting now" figure is sent. The screen adds it up out of the very
     * rows it is drawing, so the strip at the top and the blocks beneath it
     * cannot disagree — a total standing over a list it does not describe is the
     * mistake this application was caught making three times in one day.
     */
    public function figures(Request $request, School $school): JsonResponse
    {
        $user = $request->user();

        abort_unless(in_array($school->id, $this->schoolIds($user), true), 404);

        $slice = $this->slice($request);
        $season = SeasonContext::active();

        return response()->json(['data' => [
            'venue' => [
                'id' => $school->id,
                'name' => $school->name,
                'city' => $school->city,
                // The header names the country above the school (owner, 18.09).
                'country' => $school->country?->name,
            ],
            'slice' => $slice,
            /*
             * How many venues they hold, answered here as well as on Welcome.
             * The screen needs it to know whether there is ANOTHER venue to
             * offer when this one has nothing — and it must not learn that from
             * the address it was opened with, which anybody can retype.
             */
            'venues_count' => count($this->schoolIds($user)),
            'papers' => $season === null ? [] : match ($slice) {
                'upcoming' => VenueOverview::upcoming([$school->id], $season->id),
                'running' => VenueOverview::running([$school->id], $season->id),
                default => VenueOverview::published([$school->id], $season->id),
            },
        ]]);
    }

    /**
     * Which of the three ways in this request is for.
     *
     * 🪤 An unknown one is a 404 rather than a quiet fall back to the first: a
     * screen that asks for `results` and is handed `upcoming` reports the wrong
     * room state under the right heading, and nothing on the way says so.
     */
    private function slice(Request $request): string
    {
        $slice = (string) $request->query('slice', 'upcoming');

        abort_unless(in_array($slice, VenueOverview::SLICES, true), 404);

        return $slice;
    }

    /**
     * The venues this user may see, as a plain list.
     *
     * 🪤 `allowedSchoolIds()` answers `null` for somebody who may see every
     * venue, and `null` is not a scope — it has to be turned into one here, or
     * `whereIn` would be handed nothing and an administrator would see an empty
     * application. Every venue in the active season's country set, then.
     *
     * @return list<int>
     */
    private function schoolIds(User $user): array
    {
        $allowed = $user->allowedSchoolIds();

        if ($allowed !== null) {
            return $allowed->map(fn ($id) => (int) $id)->values()->all();
        }

        return School::query()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * The venue named beside their name, when there is exactly one. A country
     * coordinator's bar says how many they run instead, because naming one of
     * twenty-four would be naming the wrong one.
     *
     * @param  list<int>  $schoolIds
     * @return array<string, mixed>|null
     */
    private function soleVenue(User $user, array $schoolIds): ?array
    {
        if (count($schoolIds) !== 1) {
            return null;
        }

        $school = School::query()->find($schoolIds[0], ['id', 'name', 'city']);

        return $school === null ? null : [
            'id' => $school->id,
            'name' => $school->name,
            'city' => $school->city,
        ];
    }

    /**
     * Which kind of coordinator, in their own words. Taken from the assignment's
     * role rather than guessed from how many venues they hold: a country
     * coordinator with one venue is still a country coordinator.
     */
    private function roleLabel(User $user): string
    {
        $keys = $user->activeSeasonAssignments()
            ->map(fn (SeasonUserAssignment $a) => $a->role?->key)
            ->filter()
            ->all();

        if (in_array(SystemRole::Admin->value, $keys, true)) {
            return SystemRole::Admin->value;
        }

        if (in_array(SystemRole::CountryCoordinator->value, $keys, true)) {
            return SystemRole::CountryCoordinator->value;
        }

        return SystemRole::SchoolCoordinator->value;
    }
}
