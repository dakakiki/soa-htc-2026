<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Enums\MessageStatus;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
use App\Domain\Communication\Support\Audience;
use App\Domain\Communication\Support\MessageDispatcher;
use App\Domain\Communication\Support\RecipientResolver;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
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
            'data' => $this->withAudienceLabels($page->items()),
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

        $data = $request->validate($this->audienceRules());

        return response()->json([
            'data' => [
                'count' => $this->recipients->count($season->id, Audience::fromArray($data['audience'] ?? [])),
            ],
        ]);
    }

    /**
     * The people a filter currently matches, for the coordinator picker.
     *
     * 🔴 The same resolver again. The list the administrator ticks names out of
     * is the list the message would go to, so choosing a country and then a
     * person cannot offer somebody the country filter has already excluded.
     */
    public function recipientList(Request $request): JsonResponse
    {
        $this->authorize('messages.manage');
        $season = $this->season();

        $data = $request->validate([
            ...$this->audienceRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // The names already chosen must not narrow the list they were chosen
        // from, or removing one would be the only way to see the others again.
        $audience = Audience::fromArray(['users' => []] + ($data['audience'] ?? []));

        $query = $this->recipients->query($season->id, $audience);

        if (($data['search'] ?? '') !== '') {
            $term = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return response()->json([
            'data' => $query->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
            'meta' => ['total' => $query->count()],
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
    /**
     * One person's own notices.
     *
     * Two scopes over one shape. `waiting` is what the Welcome screen puts in
     * front of somebody — the notices they have not put away — and is the
     * default, because that is the older question and the one most callers ask.
     * `all` is the INBOX, and it exists because of what putting one away used to
     * mean.
     *
     * 🔴 Dismissing hid a notice FOR GOOD. The query is `whereNull(dismissed_at)`
     * and no screen anywhere showed the others, so a coordinator who tapped ×
     * on "print the attendance register before Friday" had no way back to it —
     * the administrator could still read it in their own list, the person it was
     * written for could not. That is the whole reason this scope exists.
     *
     * `meta.waiting` is the true count rather than the size of this page: the
     * bell in both shells is drawn from it, and a bell that stops counting at
     * twenty is a bell that lies quietly.
     */
    public function inbox(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $everything = $request->query('scope') === 'all';

        $query = $everything
            ? MessageDelivery::query()->inApp($userId)
            : MessageDelivery::query()->waitingInApp($userId);

        $deliveries = $query
            ->with('message:id,subject,body,sent_at')
            ->latest('id')
            ->limit($everything ? 100 : 20)
            ->get();

        return response()->json([
            'data' => $deliveries
                ->filter(fn (MessageDelivery $d) => $d->message !== null)
                ->map(fn (MessageDelivery $d) => [
                    'id' => $d->id,
                    'subject' => $d->message->subject,
                    'body' => $d->message->body,
                    'sent_at' => $d->message->sent_at,
                    'dismissed_at' => $d->dismissed_at,
                ])
                ->values(),
            'meta' => ['waiting' => MessageDelivery::query()->waitingInApp($userId)->count()],
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
            // One subject for every channel: a notification title and a mail
            // subject line are the same sentence.
            'subject' => ['required', 'string', 'max:200'],
            // Short, because a notification is read in one glance and the rest
            // is cut off by the phone rather than by us.
            'body' => ['nullable', 'string', 'max:500'],
            'body_html' => ['nullable', 'string', 'max:20000'],
            ...$this->audienceRules(),
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::enum(MessageChannel::class)],
            'status' => ['required', Rule::in([MessageStatus::Draft->value, MessageStatus::Scheduled->value])],
            'send_at' => ['nullable', 'date'],
        ]);

        $channels = array_values(array_unique($data['channels']));

        /*
         * 🔴 Push needs a key pair before it has anywhere to go. This refused it
         * outright until 2026-09-18, when the channel did not exist at all; now
         * it refuses only what is still true — an installation with no VAPID
         * pair would write deliveries nothing can ever pay off.
         *
         * 🪤 Narrowed rather than deleted. The condition that matters is not
         * "has push been built" but "can THIS installation send it", and a fresh
         * deployment that has not run `push:keys` is exactly that case.
         *
         * Subscriptions are deliberately NOT checked. Nobody having turned
         * notifications on yet is an ordinary state of the world, not a
         * misconfiguration, and the delivery says so in its own words when it
         * finds no device.
         */
        if (in_array(MessageChannel::Push->value, $channels, true) && ! config('push.vapid.private')) {
            throw ValidationException::withMessages([
                'channels' => 'Notifications are not set up on this installation yet.',
            ]);
        }

        /*
         * Each body is required by the channel that carries it, and by nothing
         * else. A mail-only message needs no notification text, and a message
         * that only goes to the app needs no HTML — asking for both every time
         * would leave half of every message unread and unsent.
         */
        $notification = array_intersect([MessageChannel::App->value, MessageChannel::Push->value], $channels) !== [];

        if ($notification && trim((string) ($data['body'] ?? '')) === '') {
            throw ValidationException::withMessages(['body' => 'Write the message.']);
        }

        if (in_array(MessageChannel::Mail->value, $channels, true) && trim(strip_tags((string) ($data['body_html'] ?? ''))) === '') {
            throw ValidationException::withMessages(['body_html' => 'Write the e-mail.']);
        }

        if ($data['status'] === MessageStatus::Scheduled->value && ($data['send_at'] ?? null) === null) {
            throw ValidationException::withMessages([
                'send_at' => 'A scheduled message needs a date and time.',
            ]);
        }

        return [
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            // Normalised on the way in, so what is stored is always four lists
            // of whole numbers whatever the form sent.
            'audience' => Audience::fromArray($data['audience'] ?? [])->toArray(),
            'channels' => $channels,
            'status' => MessageStatus::from($data['status']),
            'send_at' => $data['status'] === MessageStatus::Scheduled->value ? $data['send_at'] : null,
        ];
    }

    /**
     * The audience said in words, so the list does not print raw ids.
     *
     * Names are fetched once for the whole page rather than per row: two
     * queries for twenty messages instead of forty.
     *
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function withAudienceLabels(array $messages): array
    {
        $roleIds = [];
        $countryIds = [];

        foreach ($messages as $message) {
            $audience = $message->audience();
            $roleIds = array_merge($roleIds, $audience->roles);
            $countryIds = array_merge($countryIds, $audience->countries);
        }

        $roles = Role::query()->whereIn('id', array_unique($roleIds))->pluck('name', 'id');
        $countries = Country::query()->whereIn('id', array_unique($countryIds))->pluck('name', 'id');

        return array_map(function (Message $message) use ($roles, $countries): array {
            $audience = $message->audience();
            $parts = [];

            foreach ($audience->roles as $id) {
                $parts[] = (string) ($roles[$id] ?? ('#'.$id));
            }

            // Three names read; a dozen do not, and the number is what the
            // administrator was thinking in anyway.
            if (count($audience->countries) > 0 && count($audience->countries) <= 3) {
                foreach ($audience->countries as $id) {
                    $parts[] = (string) ($countries[$id] ?? ('#'.$id));
                }
            } elseif (count($audience->countries) > 3) {
                $parts[] = count($audience->countries).' countries';
            }

            if ($audience->venues !== []) {
                $parts[] = count($audience->venues).' '.(count($audience->venues) === 1 ? 'venue' : 'venues');
            }

            if ($audience->users !== []) {
                $parts[] = count($audience->users).' '.(count($audience->users) === 1 ? 'person' : 'people');
            }

            return $message->toArray() + [
                'audience_label' => $parts === [] ? 'Everyone' : implode(' · ', $parts),
            ];
        }, $messages);
    }

    /**
     * The audience, as four lists that multiply. Nothing is required: an empty
     * list narrows nothing, so an empty audience is every coordinator of the
     * season — which is a thing the administration is allowed to mean.
     *
     * @return array<string, list<string>>
     */
    private function audienceRules(): array
    {
        return [
            'audience' => ['sometimes', 'array'],
            'audience.roles' => ['sometimes', 'array'],
            'audience.roles.*' => ['integer'],
            'audience.countries' => ['sometimes', 'array'],
            'audience.countries.*' => ['integer'],
            'audience.venues' => ['sometimes', 'array'],
            'audience.venues.*' => ['integer'],
            'audience.users' => ['sometimes', 'array'],
            'audience.users.*' => ['integer'],
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
            'body_html' => $message->body_html,
            'audience' => $message->audience()->toArray(),
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
