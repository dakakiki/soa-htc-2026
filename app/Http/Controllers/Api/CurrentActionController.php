<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Console\Commands\FinalizeExpiredAttempts;
use App\Domain\Competition\Models\Attempt;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Monitoring → Current action: who is sitting an exam right now.
 *
 * 🔴 The shape is decided by one measurement, not by taste. The legacy roster's
 * busiest minute carried 6.607 results — around 110 a second — and a page that
 * is a list of rows is unreadable at that rate: a row is gone before it can be
 * read. So the counts come first and the list second, capped, with the ones
 * closest to their deadline at the top — and the filters narrow both.
 *
 * 🪤 An attempt past its deadline but still `in_progress` is not somebody
 * working. It is a browser that was closed or a connection that dropped, waiting
 * for {@see FinalizeExpiredAttempts} to close it. It is
 * counted apart, because it is the row an invigilator would want to be told
 * about and it would otherwise sit in "working now" forever.
 *
 * 🔴 Administrators only, the same reasoning as the results archive and the user
 * log: this is the whole world's competitors, and nothing here can be narrowed
 * to a coordinator's own venues honestly.
 */
class CurrentActionController extends Controller
{
    /** How many rows the list carries. The counts above it cover the rest. */
    private const LIST_CAP = 100;

    /** Submissions inside this window make the "still coming in" number. */
    private const RECENT_MINUTES = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('reports.view');
        $this->assertGlobalScope($request);

        $now = now();
        $deadline = $now->copy()->subSeconds(Attempt::SUBMIT_GRACE_SECONDS);

        /*
         * \U0001F534 The filters drive the counts as well as the list. A number above a
         * list it does not describe is the exact mistake this application was
         * caught making three times over on 2026-09-17 — narrowed to one venue,
         * "sitting now" has to mean that venue.
         */
        $open = fn () => $this->scoped($request);

        return response()->json(['data' => [
            'as_of' => $now->toIso8601String(),
            'counts' => [
                'running' => $open()->where('attempts.expires_at', '>=', $deadline)->count(),
                'overdue' => $open()->where('attempts.expires_at', '<', $deadline)->count(),
                'submitted_recently' => $this->scoped($request, open: false)
                    ->where('attempts.status', '!=', 'void')
                    ->where('attempts.submitted_at', '>=', $now->copy()->subMinutes(self::RECENT_MINUTES))
                    ->count(),
                'venues' => $open()->distinct()->count('r.school_id'),
                'recent_minutes' => self::RECENT_MINUTES,
            ],
            'rows' => $this->rows($request, $deadline),
        ]]);
    }

    /**
     * Attempts inside the chosen population.
     *
     * The joins are always there rather than added when a filter is set: the
     * venue count needs `registrations` anyway, and one shape is easier to read
     * than two that differ by which filter happens to be on.
     *
     * @return Builder<Attempt>
     */
    private function scoped(Request $request, bool $open = true)
    {
        return Attempt::query()
            ->join('registrations as r', 'r.id', '=', 'attempts.registration_id')
            ->leftJoin('schools as s', 's.id', '=', 'r.school_id')
            ->when($open, fn ($q) => $q->where('attempts.status', 'in_progress'))
            ->when($request->integer('country_id') > 0, fn ($q) => $q->where('r.country_id', $request->integer('country_id')))
            // Region lives on the venue, not on the registration.
            ->when($request->integer('region_id') > 0, fn ($q) => $q->where('s.region_id', $request->integer('region_id')))
            ->when($request->integer('school_id') > 0, fn ($q) => $q->where('r.school_id', $request->integer('school_id')));
    }

    /**
     * The open attempts themselves, the closest to running out first.
     *
     * 🪤 Ordered by `expires_at` ascending rather than by when they started: what
     * an invigilator needs is who is about to lose their chance, and an attempt
     * already past its deadline sorts to the very top on its own.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Request $request, Carbon $deadline): array
    {
        return $this->scoped($request)
            ->leftJoin('countries as c', 'c.id', '=', 'r.country_id')
            ->leftJoin('difficulty_levels as dl', 'dl.id', '=', 'r.difficulty_level_id')
            // The red count is an alarm; this is the click that shows who it is about.
            ->when($request->boolean('overdue'), fn ($q) => $q->where('attempts.expires_at', '<', $deadline))
            ->leftJoin('tests as t', 't.id', '=', 'attempts.test_id')
            ->leftJoin('quizzes as qz', 'qz.id', '=', 'attempts.quiz_id')
            // One box for the call that actually comes in: "competitor X has a problem".
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(function ($w) use ($term): void {
                    $w->where('r.competitor_number', 'like', $term)->orWhere('r.name', 'like', $term);
                });
            })
            /*
             * 🪤 The columns go in `select()`, not in `get()`. `withCount()` sets a
             * select of its own (`attempts.*`), and a column list handed to `get()`
             * is only honoured when nothing has selected yet — so it was silently
             * dropped and every joined field arrived null.
             */
            ->select([
                'attempts.id', 'attempts.started_at', 'attempts.expires_at', 'attempts.registration_id',
                'r.competitor_number', 'r.name as student_name', 'c.name as country', 's.name as venue',
                'dl.level_short as level', 't.title as test', 'qz.title as quiz',
            ])
            ->withCount('answers')
            ->orderBy('attempts.expires_at')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (Attempt $a): array => [
                'id' => $a->id,
                'registration_id' => $a->registration_id,
                'competitor_number' => $a->competitor_number,
                'name' => $a->student_name,
                'country' => $a->country,
                'venue' => $a->venue,
                'level' => $a->level,
                'quiz' => $a->quiz,
                'test' => $a->test,
                'started_at' => $a->started_at?->toIso8601String(),
                'expires_at' => $a->expires_at?->toIso8601String(),
                'answered' => $a->answers_count,
                // Decided here rather than in the browser: the server's clock is the
                // one the deadline was written against.
                'overdue' => $a->expires_at !== null && $a->expires_at->lt($deadline),
            ])
            ->all();
    }

    private function assertGlobalScope(Request $request): void
    {
        abort_if(
            $request->user()?->allowedSchoolIds() !== null,
            Response::HTTP_FORBIDDEN,
            'Current action covers every venue at once, so it is open to administrators only.',
        );
    }
}
