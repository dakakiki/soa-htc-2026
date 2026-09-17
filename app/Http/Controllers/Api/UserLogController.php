<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Support\AuditTrail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Monitoring → User log: who got in, and what they did to the authority surface.
 *
 * Reads the trail {@see AuditTrail} writes — sign-ins
 * and sign-outs, failed attempts, and changes to roles, accounts and season
 * assignments. Asked for by the owner on 2026-09-17 after accounts had been used
 * for things nobody could afterwards pin on anybody.
 *
 * 🔴 Administrators only, and not because of the permission alone. `users.manage`
 * has no gate of its own — the staff-users area is governed by {@see UserPolicy},
 * so this asks the same question that screen does. The trail also has
 * no venue column and cannot be narrowed to one, so a coordinator holding it
 * through a custom role would otherwise read every sign-in in the world. An approximate boundary is worse than an honest refusal, because it
 * looks like it is working. Same reasoning as the results archive.
 */
class UserLogController extends Controller
{
    private const PER_PAGE_MAX = 200;

    /** Rows beyond this are not exported; the filters are there to narrow first. */
    private const EXPORT_CAP = 20000;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $this->assertGlobalScope($request);

        $paginated = $this->query($request)->paginate(
            min(max($request->integer('per_page', 50), 1), self::PER_PAGE_MAX)
        );

        return response()->json([
            'data' => array_map($this->row(...), $paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * What the filters can offer: the actions that actually occur and the people
     * who appear. Read from the trail rather than from a hard-coded list, so a
     * new action starts being filterable the day it is first written.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $this->assertGlobalScope($request);

        return response()->json(['data' => [
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            // So the screen can warn BEFORE a truncated download rather than after.
            'export_cap' => self::EXPORT_CAP,
            'actors' => AuditLog::query()
                ->whereNotNull('actor_id')
                ->select(['actor_id', 'actor_label'])
                ->distinct()
                ->orderBy('actor_label')
                ->get()
                ->map(fn (AuditLog $r): array => ['id' => (int) $r->actor_id, 'label' => $r->actor_label])
                ->values()
                ->all(),
        ]]);
    }

    public function export(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        $this->assertGlobalScope($request);

        $rows = $this->query($request)->limit(self::EXPORT_CAP)->get()
            ->map(fn (AuditLog $r): array => [
                $r->created_at?->format('Y-m-d H:i:s'),
                $r->actor_label ?? '',
                $r->actor_id !== null ? (string) $r->actor_id : '',
                $r->action,
                $this->subjectLabel($r),
                $r->ip_address ?? '',
                $this->details($r),
                $r->reason ?? '',
            ])
            ->all();

        $headers = ['When', 'Who', 'User ID', 'Action', 'Subject', 'IP address', 'Details', 'Reason'];
        $filename = now()->format('Y-m-d').'_User_Log.xlsx';

        return response(XlsxWriter::toString($headers, $rows, 'User log'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The filtered trail, newest first.
     *
     * @return Builder<AuditLog>
     */
    private function query(Request $request)
    {
        return AuditLog::query()
            ->when($request->integer('actor_id') > 0, fn ($q) => $q->where('actor_id', $request->integer('actor_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')->toString()))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')?->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')?->endOfDay()))
            // One box for "I know roughly who or where from": the name kept beside
            // the id, the address a failed sign-in typed, and the ip.
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q')->toString().'%';
                $q->where(function ($w) use ($term): void {
                    $w->where('actor_label', 'like', $term)
                        ->orWhere('ip_address', 'like', $term)
                        ->orWhere('after', 'like', $term);
                });
            })
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function row(AuditLog $r): array
    {
        return [
            'id' => $r->id,
            'created_at' => $r->created_at?->toIso8601String(),
            'actor_id' => $r->actor_id,
            'actor_label' => $r->actor_label,
            'action' => $r->action,
            'subject' => $this->subjectLabel($r),
            'ip_address' => $r->ip_address,
            'details' => $this->details($r),
            'reason' => $r->reason,
            'before' => $r->before,
            'after' => $r->after,
        ];
    }

    /**
     * The subject as a reader would name it: the class without its namespace,
     * plus its id. The full namespace says nothing to somebody reading a log and
     * is the kind of thing API responses were hardened against elsewhere.
     */
    private function subjectLabel(AuditLog $r): string
    {
        if ($r->subject_type === null) {
            return '';
        }

        return class_basename($r->subject_type).' #'.($r->subject_id ?? '?');
    }

    /**
     * One line saying what the row is about.
     *
     * For a change, the FIELDS that differ — not their values, which belong in
     * the expanded row where somebody has chosen to look. For a sign-in, the
     * browser; for a failure, the address that was typed, which is the whole
     * reason the row exists.
     */
    private function details(AuditLog $r): string
    {
        $after = is_array($r->after) ? $r->after : [];
        $before = is_array($r->before) ? $r->before : [];

        if ($before !== [] && $after !== []) {
            $changed = array_keys(array_filter(
                $after,
                fn ($value, string $key): bool => ! array_key_exists($key, $before) || $before[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            return $changed === [] ? '' : 'changed: '.implode(', ', $changed);
        }

        if (isset($after['email']) && is_string($after['email'])) {
            return 'tried: '.$after['email'];
        }

        if (isset($after['user_agent']) && is_string($after['user_agent'])) {
            return $after['user_agent'];
        }

        return $after === [] ? '' : implode(', ', array_keys($after));
    }

    private function assertGlobalScope(Request $request): void
    {
        abort_if(
            $request->user()?->allowedSchoolIds() !== null,
            Response::HTTP_FORBIDDEN,
            'The user log is not scoped to particular venues, so it is open to administrators only.',
        );
    }
}
