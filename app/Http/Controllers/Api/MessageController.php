<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Enums\MessageAudience;
use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Enums\MessageStatus;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
use App\Domain\Communication\Support\MessageDispatcher;
use App\Domain\Communication\Support\RecipientResolver;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Support\SeasonContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Messages the administration writes for coordinators (owner, 2026-09-15).
 *
 * One text, several channels, and an audience described as a filter. What was
 * composed lives in `messages`; what came of it lives in `message_deliveries`,
 * and this controller never lets the first pretend to be the second.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('messages.manage');
        $season = $this->season();

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', Rule::enum(MessageStatus::class)],
            'channel' => ['sometimes', 'nullable', Rule::enum(MessageChannel::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Message::query()
            ->where('season_id', $season->id)
            ->with('author:id,name')
            ->withCount([
                // What actually left, as opposed to what was addressed. The two
                // differ the moment a mail server refuses an address.
                'deliveries as delivered_count' => fn ($q) => $q->where('status', MessageDelivery::STATUS_SENT),
                'deliveries as failed_count' => fn ($q) => $q->where('status', MessageDelivery::STATUS_FAILED),
            ])
            ->latest('id');

        if (($filters['search'] ?? '') !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('subject', 'like', $term)->orWhere('body', 'like', $term));
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        // 🪤 `channels` is a JSON list, and SQLite and MySQL disagree about how
        // to search one. A LIKE over the encoded list is the same sentence on
        // both engines, and the values are a closed set of short words that
        // cannot collide ("app", "mail", "push").
        if (($filters['channel'] ?? '') !== '') {
            $query->where('channels', 'like', '%"'.$filters['channel'].'"%');
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));

        // 🪤 `data` and `meta`, not the paginator's own flat shape: every list in
        // the SPA reads `meta.total` and `meta.last_page`, and a page returned
        // any other way reaches the screen as "could not load".
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Message $message): JsonResponse
    {
        $this->authorize('messages.manage');

        return response()->json(['data' => $this->present($message)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('messages.manage');
        $season = $this->season();
        $data = $this->validated($request);

        $message = Message::create([
            'season_id' => $season->id,
            'created_by' => (int) $request->user()->id,
            ...$data,
        ]);

        return response()->json(['data' => $this->present($message)], 201);
    }

    /**
     * A message that has gone out is a record of what people were told, so it
     * is not editable: changing the text afterwards would make the record
     * disagree with every inbox that already holds it.
     */
    public function update(Request $request, Message $message): JsonResponse
    {
        $this->authorize('messages.manage');
        $this->refuseIfSent($message);

        $message->update($this->validated($request));

        return response()->json(['data' => $this->present($message->fresh())]);
    }

    public function destroy(Message $message): Response
    {
        $this->authorize('messages.manage');
        $this->refuseIfSent($message);

        $message->delete();

        return response()->noContent();
    }

    /**
     * How many coordinators an audience comes to, answered before sending.
     *
     * 🔴 The same resolver that will pick the people answers this, so the
     * number beside the picker and the number that goes out cannot drift.
     */
    public function recipients(Request $request): JsonResponse
    {
        $this->authorize('messages.manage');
        $season = $this->season();

        $data = $request->validate([
            'audience_type' => ['required', Rule::enum(MessageAudience::class)],
            'audience_ids' => ['sometimes', 'array'],
            'audience_ids.*' => ['integer'],
        ]);

        $audience = MessageAudience::from($data['audience_type']);

        return response()->json([
            'data' => [
                'count' => $this->recipients->count($season->id, $audience, array_map('intval', $data['audience_ids'] ?? [])),
            ],
        ]);
    }

    /**
     * Send it now.
     *
     * Only the writing is done here: the in-app notices are rows, so they are
     * on every screen at once, and the mails are left owed for `messages:send`
     * to pay off. Four hundred addresses do not belong in a web request.
     */
    public function send(Message $message): JsonResponse
    {
        $this->authorize('messages.manage');
        $this->refuseIfSent($message);

        $this->dispatcher->dispatch($message);

        return response()->json(['data' => $this->present($message->fresh())]);
    }

    /**
     * What is waiting for the coordinator who is signed in.
     *
     * No permission: this is not the administration's screen but the
     * coordinator's own, and it can only ever return what was addressed to
     * them.
     */
    public function inbox(Request $request): JsonResponse
    {
        $deliveries = MessageDelivery::query()
            ->waitingInApp((int) $request->user()->id)
            ->with('message:id,subject,body,sent_at')
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $deliveries
                ->filter(fn (MessageDelivery $d) => $d->message !== null)
                ->map(fn (MessageDelivery $d) => [
                    'id' => $d->id,
                    'subject' => $d->message->subject,
                    'body' => $d->message->body,
                    'sent_at' => $d->message->sent_at,
                ])
                ->values(),
        ]);
    }

    /** The coordinator has read it and put it away. Their own row, nobody else's. */
    public function dismiss(Request $request, MessageDelivery $delivery): Response
    {
        abort_unless($delivery->user_id === (int) $request->user()->id, 404);

        $delivery->forceFill(['dismissed_at' => now()])->save();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'audience_type' => ['required', Rule::enum(MessageAudience::class)],
            'audience_ids' => ['sometimes', 'array'],
            'audience_ids.*' => ['integer'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(MessageChannel::class)],
            'status' => ['required', Rule::in([MessageStatus::Draft->value, MessageStatus::Scheduled->value])],
            'send_at' => ['nullable', 'date'],
        ]);

        $audience = MessageAudience::from($data['audience_type']);
        $ids = array_map('intval', $data['audience_ids'] ?? []);

        // Every audience but "everyone" is a filter over something, and a
        // filter over nothing would quietly address the whole season.
        if ($audience !== MessageAudience::All && $ids === []) {
            throw ValidationException::withMessages([
                'audience_ids' => 'Choose at least one.',
            ]);
        }

        $channels = array_values(array_unique($data['channels']));

        // The app channel is not optional: it is the one that needs no address
        // and no permission, so it is the one a message can always rely on.
        if (! in_array(MessageChannel::App->value, $channels, true)) {
            $channels[] = MessageChannel::App->value;
        }

        // 🔴 Push has nowhere to go yet — no VAPID keys and no subscriptions.
        // Accepting it would write deliveries nothing will ever pay off.
        if (in_array(MessageChannel::Push->value, $channels, true)) {
            throw ValidationException::withMessages([
                'channels' => 'Push is not available yet.',
            ]);
        }

        if ($data['status'] === MessageStatus::Scheduled->value && ($data['send_at'] ?? null) === null) {
            throw ValidationException::withMessages([
                'send_at' => 'A scheduled message needs a date and time.',
            ]);
        }

        return [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'audience_type' => $audience,
            'audience_ids' => $audience === MessageAudience::All ? null : $ids,
            'channels' => $channels,
            'status' => MessageStatus::from($data['status']),
            'send_at' => $data['status'] === MessageStatus::Scheduled->value ? $data['send_at'] : null,
        ];
    }

    private function refuseIfSent(Message $message): void
    {
        abort_if($message->isSent(), 422, 'This message has already gone out.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Message $message): array
    {
        return [
            'id' => $message->id,
            'subject' => $message->subject,
            'body' => $message->body,
            'audience_type' => $message->audience_type,
            'audience_ids' => $message->audience_ids,
            'channels' => $message->channels,
            'status' => $message->status,
            'send_at' => $message->send_at,
            'sent_at' => $message->sent_at,
            'recipients_count' => $message->recipients_count,
        ];
    }

    private function season(): Season
    {
        $season = SeasonContext::active();

        abort_if($season === null, 422, 'There is no active season.');

        return $season;
    }
}
