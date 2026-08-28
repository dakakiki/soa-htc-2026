<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Support\SeasonContext;
use App\Domain\Organization\Support\SeasonRollover;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartSeasonRequest;
use App\Http\Resources\SeasonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Season. Legacy kept the round and the school year as two fields on
 * an admin settings card (`el_settings`, one row, no primary key) and let them be
 * typed over in place; the running competitor counter had its own checkbox next to
 * them, because nothing else could restart it.
 *
 * Here the same two fields open a season instead of editing one. Saving the form
 * archives the outgoing season, wipes it and makes the new round active in one
 * transaction — so the competitor sequence, which is scoped by season, restarts on
 * its own and the numbers already issued keep the round they were issued under.
 */
class SeasonController extends Controller
{
    /**
     * The active season, what starting the next one would clear, and the values to
     * open the form with. The counts are the whole reason the form is not just two
     * inputs: this is irreversible, so it says what it will take with it.
     */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), Response::HTTP_FORBIDDEN);

        $active = SeasonContext::active();

        return response()->json([
            'active' => $active === null ? null : SeasonResource::make($active)->resolve($request),
            'plan' => SeasonRollover::plan($active?->id),
            'history' => $this->history(),
            // The obvious next round and school year — still editable, never assumed.
            'suggested' => [
                'round_number' => ($active?->round_number ?? 0) + 1,
                'year' => ($active?->year ?? (int) date('Y')) + 1,
            ],
        ]);
    }

    /**
     * Every rollover this installation has made, newest first (ADR-0068).
     *
     * `audit_logs` had exactly one writer and no reader, and the row it wrote was
     * deleted by the next rollover — so the trail of season starts could never be
     * read, by anybody, ever. Keeping the seasons' own rows fixed half of that.
     * This is the other half, and it is deliberately NOT a general audit browser:
     * it answers the question this screen is already about, in the place somebody
     * is standing when they ask it.
     *
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        return AuditLog::query()
            ->where('action', 'season.started')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $row): array => [
                'at' => $row->created_at?->toIso8601String(),
                // The label is kept alongside the id on purpose: deleting the
                // account nulls `actor_id`, and a trail that forgets who did it
                // the moment they leave is not a trail. Null means the console.
                'by' => $row->actor_label,
                'round' => $row->after['round_number'] ?? null,
                'year' => $row->after['year'] ?? null,
                'previous_round' => $row->before['round_number'] ?? null,
                'archived_registrations' => $row->before['archived_registrations'] ?? null,
            ])
            ->all();
    }

    /**
     * Start the next season. Everything below happens or nothing does.
     */
    public function store(StartSeasonRequest $request): JsonResponse
    {
        $result = SeasonRollover::start($request->safe()->except('confirm'), $request->user());

        return response()->json([
            'season' => SeasonResource::make($result['season'])->resolve($request),
            'applied' => $result['applied'],
        ], Response::HTTP_CREATED);
    }
}
