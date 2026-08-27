<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Enums\CoordinatorRegistrationStatus;
use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Domain\Organization\Support\CoordinatorApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorRegistrationRequest;
use App\Http\Resources\CoordinatorRegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public coordinator registration, and the queue that decides it (ADR-0053).
 *
 * One controller for two audiences, which is unusual here and deliberate: an
 * application and its decision are the same object, and splitting them would put
 * the private disk path in two places. `store` is the ONLY method without an
 * authorization check — every other one is gated on `coordinators.approve`.
 */
class CoordinatorRegistrationController extends Controller
{
    /** Where the signed approvals live on the private disk. */
    private const DOCUMENT_DIRECTORY = 'coordinator-approvals';

    /**
     * Receive an application. Public, unauthenticated, rate limited by route.
     *
     * The response says nothing except that it was received: there is no account
     * yet, nothing to sign in to, and no token. What the applicant gets back is
     * the second step of the screen they are already on.
     */
    public function store(StoreCoordinatorRegistrationRequest $request): JsonResponse
    {
        $document = $request->file('document');

        // 🪤 The private disk, not `public`. A signed venue approval carries a
        // school's letterhead and somebody's signature, and until a reviewer says
        // otherwise the person who uploaded it is a stranger. `users.file_path`
        // stores documents of people already inside, on the public disk, where the
        // URL is the whole of the protection; that would be the wrong home for
        // this one. Downloads go through `document()` below.
        $path = $document->store(self::DOCUMENT_DIRECTORY, 'local');

        CoordinatorRegistration::create([
            'name' => $request->string('name')->trim()->value(),
            'email' => $request->string('email')->trim()->value(),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'country_id' => $request->integer('country_id'),
            'password' => Hash::make((string) $request->input('password')),
            'document_path' => $path,
            'document_name' => $document->getClientOriginalName(),
            'document_mime' => (string) ($document->getClientMimeType() ?: 'application/octet-stream'),
            'document_size' => (int) $document->getSize(),
            'status' => CoordinatorRegistrationStatus::Pending,
        ]);

        return response()->json(['data' => ['received' => true]], Response::HTTP_CREATED);
    }

    /**
     * The review queue. Waiting applications first and oldest first, because the
     * queue is worked from the top and somebody has been waiting longest.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('coordinators.approve');

        $query = CoordinatorRegistration::query()->with(['country:id,name', 'reviewer:id,name']);

        $status = CoordinatorRegistrationStatus::tryFrom((string) $request->input('status', ''));

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', $request->integer('country_id'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        $registrations = $query
            // Waiting first whatever else is on screen, then oldest first.
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->paginate(min(max($request->integer('per_page', 10), 1), 100))
            ->withQueryString();

        return CoordinatorRegistrationResource::collection($registrations);
    }

    public function show(CoordinatorRegistration $registration): CoordinatorRegistrationResource
    {
        $this->authorize('coordinators.approve');

        return CoordinatorRegistrationResource::make(
            $registration->load(['country:id,name', 'reviewer:id,name']),
        );
    }

    /**
     * The signed venue approval itself.
     *
     * Streamed from the private disk under the same permission that decides the
     * application — the document is the evidence the decision is made on, so
     * seeing it and deciding are one job, not two.
     */
    public function document(CoordinatorRegistration $registration): StreamedResponse
    {
        $this->authorize('coordinators.approve');

        abort_unless(Storage::disk('local')->exists($registration->document_path), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->download(
            $registration->document_path,
            $registration->document_name,
            ['Content-Type' => $registration->document_mime],
        );
    }

    /** Open the account. */
    public function approve(Request $request, CoordinatorRegistration $registration): CoordinatorRegistrationResource
    {
        $this->authorize('coordinators.approve');

        CoordinatorApproval::approve($registration, $request->user());

        return CoordinatorRegistrationResource::make(
            $registration->refresh()->load(['country:id,name', 'reviewer:id,name']),
        );
    }

    /** Refuse it, with a note for the other reviewers. */
    public function decline(Request $request, CoordinatorRegistration $registration): CoordinatorRegistrationResource
    {
        $this->authorize('coordinators.approve');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        CoordinatorApproval::decline($registration, $request->user(), $validated['reason'] ?? null);

        return CoordinatorRegistrationResource::make(
            $registration->refresh()->load(['country:id,name', 'reviewer:id,name']),
        );
    }

    /**
     * Delete a decided application, and its document with it.
     *
     * Only a decided one: the queue is not a place things disappear from before
     * anybody has looked at them. The account an approval created is untouched —
     * it is a user now, and users are managed on their own screen.
     */
    public function destroy(CoordinatorRegistration $registration): Response
    {
        $this->authorize('coordinators.approve');

        abort_if($registration->status === CoordinatorRegistrationStatus::Pending, Response::HTTP_UNPROCESSABLE_ENTITY);

        Storage::disk('local')->delete($registration->document_path);
        $registration->delete();

        return response()->noContent();
    }

    /**
     * How many are waiting. The admin shell asks for this to badge the menu item;
     * it is the only signal there is that somebody is waiting, since the decision
     * mails go to the applicant and nothing is mailed inward.
     */
    public function pendingCount(): JsonResponse
    {
        $this->authorize('coordinators.approve');

        return response()->json(['data' => ['pending' => CoordinatorRegistration::query()->pending()->count()]]);
    }
}
