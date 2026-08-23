<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\CoordinatorExporter;
use App\Domain\Organization\Support\CoordinatorImporter;
use App\Domain\Organization\Support\CoordinatorScope;
use App\Domain\Organization\Support\SeasonContext;
use App\Domain\Organization\Support\VenueCompetitorCounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorRequest;
use App\Http\Requests\UpdateCoordinatorRequest;
use App\Http\Resources\CoordinatorResource;
use App\Models\User;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CoordinatorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyCoordinator', User::class);

        $roleIds = $this->manageableRoleIds();
        $seasonId = SeasonContext::active()?->id;

        $users = $this->filteredCoordinators($request, $roleIds, $seasonId)
            ->with([
                'country',
                'region',
                'seasonAssignments' => function ($query) use ($roleIds, $seasonId): void {
                    $query->whereIn('role_id', $roleIds)
                        ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
                        ->with(['role', 'schools.country']);
                },
            ])
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 200))
            ->withQueryString();

        $this->attachVenueCounts($users->getCollection());

        return CoordinatorResource::collection($users);
    }

    /**
     * Export the currently filtered coordinators to .xlsx (same layout as the
     * import template, so a file can round-trip). Same filters and scope as
     * {@see index()}, but the whole matching set rather than one page.
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAnyCoordinator', User::class);

        $roleIds = $this->manageableRoleIds();
        $seasonId = SeasonContext::active()?->id;

        $ids = $this->filteredCoordinators($request, $roleIds, $seasonId)->pluck('id')->all();

        [$headers, $rows] = CoordinatorExporter::export($ids, $roleIds, $seasonId);

        $filename = now()->format('Y-m-d').'_Coordinators_Export.xlsx';

        return response(XlsxWriter::toString($headers, $rows, 'Coordinators'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The coordinators import template (.xlsx): the column headers plus one
     * instructions row (skipped on import). Downloaded from the import modal.
     */
    public function importTemplate(): Response
    {
        $this->authorize('create', User::class);

        $hint = [
            'e.g. John Smith', 'name@example.com', 'Serbia', 'Vojvodina (optional)',
            'Venue A, Venue B (optional)', 'Belgrade (optional)', '(optional)', '(optional)',
            'active or inactive', 'Yes/No', 'Yes/No', 'Yes/No', 'Yes/No',
        ];

        return response(XlsxWriter::toString(CoordinatorExporter::HEADERS, [$hint], 'Coordinators'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="coordinators-import-template.xlsx"',
        ]);
    }

    /**
     * Bulk-create country coordinators from an uploaded .xlsx ({@see CoordinatorImporter}).
     * Returns {created} on success, or {created:0, error_count} (HTTP 422) when the
     * file has any invalid row — the annotated file comes from {@see importErrors()}.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }

        $summary = CoordinatorImporter::import($rows);

        return response()->json($summary, $summary['error_count'] === 0 ? 200 : 422);
    }

    /**
     * The uploaded coordinator file returned with an "Error" column filled in per
     * invalid row ({@see CoordinatorImporter::errorReport()}) — the user fixes it
     * and re-uploads.
     */
    public function importErrors(Request $request): Response
    {
        $this->authorize('create', User::class);

        $validated = $request->validate(['file' => ['required', 'file', 'max:10240']]);

        try {
            $rows = XlsxReader::read((string) $validated['file']->getRealPath());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a valid .xlsx.')]);
        }

        return response(CoordinatorImporter::errorReport($rows), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="coordinators-import-errors.xlsx"',
        ]);
    }

    /**
     * Fill in the competitor counts on every venue these coordinators scope, so the
     * venues modal shows the same BH…S5 figures as the venues list. One query for
     * the whole page.
     *
     * @param  Collection<int, User>  $users
     */
    private function attachVenueCounts(Collection $users): void
    {
        $schools = $users
            ->flatMap(fn (User $user) => $user->relationLoaded('seasonAssignments') ? $user->seasonAssignments : [])
            ->flatMap(fn (SeasonUserAssignment $assignment) => $assignment->relationLoaded('schools') ? $assignment->schools : []);

        if ($schools->isEmpty()) {
            return;
        }

        $counts = VenueCompetitorCounts::for($schools->pluck('id')->unique()->values()->all());
        $schools->each(function ($school) use ($counts): void {
            $school->level_counts = $counts[$school->id] ?? [];
        });
    }

    /**
     * The base coordinator query with all list filters + the coordinator-role /
     * active-season scope applied — shared by {@see index()} and {@see export()}.
     *
     * @param  Collection<int, int>  $roleIds
     */
    private function filteredCoordinators(Request $request, Collection $roleIds, ?int $seasonId): Builder
    {
        $scoped = function ($query) use ($roleIds, $seasonId): void {
            $query->whereIn('role_id', $roleIds)
                ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId));
        };

        $actor = $request->user();

        return User::query()
            ->whereHas('seasonAssignments', $scoped)
            // A country coordinator only ever sees their own country's people;
            // the role restriction rides along in $roleIds.
            ->when(
                ! $actor->hasPermission('users.manage'),
                fn ($query) => $query->where('country_id', $actor->country_id)
            )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('city', 'like', $term));
            })
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->when($request->filled('region_id'), fn ($q) => $q->where('region_id', $request->integer('region_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('role_id'), function ($query) use ($request, $seasonId): void {
                $query->whereHas('seasonAssignments', function ($q) use ($request, $seasonId): void {
                    $q->where('role_id', $request->integer('role_id'))
                        ->when($seasonId, fn ($qq) => $qq->where('season_id', $seasonId));
                });
            })
            ->when($request->filled('school_id'), function ($query) use ($request, $roleIds, $seasonId): void {
                $query->whereHas('seasonAssignments', function ($q) use ($request, $roleIds, $seasonId): void {
                    $q->whereIn('role_id', $roleIds)
                        ->when($seasonId, fn ($qq) => $qq->where('season_id', $seasonId))
                        ->whereHas('schools', fn ($s) => $s->where('schools.id', $request->integer('school_id')));
                });
            });
    }

    public function show(User $coordinator): CoordinatorResource
    {
        $this->authorize('manageCoordinator', $coordinator);

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    public function store(StoreCoordinatorRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id', 'school_ids']);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::create($data);
            $this->syncCoordinator($user, $request->integer('role_id'), $this->schoolIds($request));

            return $user;
        });

        return CoordinatorResource::make($this->loadCoordinator($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateCoordinatorRequest $request, User $coordinator): CoordinatorResource
    {
        $data = $request->safe()->except(['image', 'file_upload', 'role_id', 'school_ids']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($request->hasFile('image')) {
            if ($coordinator->image_path) {
                Storage::disk('public')->delete($coordinator->image_path);
            }
            $data['image_path'] = $request->file('image')->store('users', 'public');
        }
        if ($request->hasFile('file_upload')) {
            if ($coordinator->file_path) {
                Storage::disk('public')->delete($coordinator->file_path);
            }
            $data['file_path'] = $request->file('file_upload')->store('users', 'public');
        }

        DB::transaction(function () use ($request, $coordinator, $data): void {
            $coordinator->update($data);
            if ($request->filled('role_id')) {
                $this->syncCoordinator($coordinator, $request->integer('role_id'), $this->schoolIds($request));
            }
        });

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    /**
     * Remove one uploaded asset (profile image / attached file): delete the file from
     * storage and clear its column, freeing the field for a new upload.
     */
    public function deleteAsset(User $coordinator, string $asset): CoordinatorResource
    {
        $this->authorize('manageCoordinator', $coordinator);

        $column = match ($asset) {
            'image' => 'image_path',
            'file' => 'file_path',
            default => abort(404),
        };

        if ($coordinator->{$column}) {
            Storage::disk('public')->delete($coordinator->{$column});
            $coordinator->update([$column => null]);
        }

        return CoordinatorResource::make($this->loadCoordinator($coordinator));
    }

    public function destroy(Request $request, User $coordinator): Response
    {
        $this->authorize('manageCoordinator', $coordinator);

        if ($request->user()->id === $coordinator->id) {
            throw ValidationException::withMessages(['user' => [trans('messages.user.self_delete')]]);
        }

        foreach ([$coordinator->image_path, $coordinator->file_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        $coordinator->delete();

        return response()->noContent();
    }

    /**
     * Replace the user's coordinator assignment for the active season with the
     * selected role and school scope.
     *
     * @param  list<int>  $schoolIds
     */
    private function syncCoordinator(User $user, int $roleId, array $schoolIds): void
    {
        $season = SeasonContext::active();
        if ($season === null) {
            throw ValidationException::withMessages([
                'role_id' => [trans('validation.required', ['attribute' => 'season'])],
            ]);
        }

        $user->seasonAssignments()
            ->where('season_id', $season->id)
            ->whereIn('role_id', $this->coordinatorRoleIds())
            ->delete();

        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'status' => 'active',
        ]);
        $assignment->schools()->sync($schoolIds);
    }

    /** @return Collection<int, int> */
    private function coordinatorRoleIds()
    {
        return Role::whereIn('key', CoordinatorScope::ROLE_KEYS)->pluck('id');
    }

    /**
     * The roles the signed-in user may see and manage here. An admin manages both
     * coordinator roles; a country coordinator only school coordinators — the same
     * ceiling the legacy screen had (user_level 5 could pick level 1 only).
     *
     * @return Collection<int, int>
     */
    private function manageableRoleIds(): Collection
    {
        if (request()->user()?->hasPermission('users.manage')) {
            return $this->coordinatorRoleIds();
        }

        return Role::whereIn('key', [SystemRole::SchoolCoordinator->value])->pluck('id');
    }

    /** @return list<int> */
    private function schoolIds(Request $request): array
    {
        return array_map('intval', (array) $request->input('school_ids', []));
    }

    private function loadCoordinator(User $user): User
    {
        $roleIds = $this->coordinatorRoleIds();
        $seasonId = SeasonContext::active()?->id;

        return $user->refresh()->load([
            'country',
            'region',
            'seasonAssignments' => function ($query) use ($roleIds, $seasonId): void {
                $query->whereIn('role_id', $roleIds)
                    ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
                    ->with(['role', 'schools.country']);
            },
        ]);
    }
}
