<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Enums\MessageStatus;
use App\Domain\Communication\Enums\PushOutcome;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
use App\Domain\Communication\Models\PushSubscription;
use App\Mail\CoordinatorMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sending a message, in two halves that run at different speeds.
 *
 * The app channel is a row in a table: writing it IS the delivery, so the whole
 * audience is served in one insert and the notice is on every coordinator's
 * screen before the administrator's click has finished.
 *
 * Mail is somebody else's server, one address at a time, and four hundred of
 * them do not fit in a web request. So `dispatch` only writes what is owed and
 * `deliverPending` — run by `messages:send` from cron — pays it off in batches.
 *
 * 🪤 "Sent" on a delivery row means the mailer accepted it, not that anybody
 * read it. Nothing in this application can know the second thing, and a column
 * that claimed to would be the kind of number that measures something other
 * than its own name.
 */
class MessageDispatcher
{
    /** Rows per insert. Kept well under SQLite's variable ceiling. */
    private const INSERT_CHUNK = 400;

    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly PushSender $push,
    ) {}

    /**
     * Work out who the message goes to, write a delivery for each of them on
     * each channel, and close the message.
     *
     * @return int The number of people it went to.
     */
    public function dispatch(Message $message): int
    {
        $users = $this->recipients->users($message->season_id, $message->audience());

        $channels = $message->channelCases();
        $now = now();
        $rows = [];

        foreach ($users as $user) {
            foreach ($channels as $channel) {
                $instant = $channel === MessageChannel::App;

                $rows[] = [
                    'message_id' => $message->id,
                    'user_id' => $user->id,
                    'channel' => $channel->value,
                    'status' => $instant ? MessageDelivery::STATUS_SENT : MessageDelivery::STATUS_PENDING,
                    'sent_at' => $instant ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows, $message, $users, $now): void {
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                DB::table('message_deliveries')->insert($chunk);
            }

            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => $now,
                'recipients_count' => $users->count(),
            ])->save();
        });

        return $users->count();
    }

    /**
     * Hand the owed mails to the mailer, marking each row with what came of it.
     *
     * A refused address is one failed row, never a failed run: the next
     * coordinator in the batch has done nothing wrong and still needs the mail.
     *
     * @return array{sent: int, failed: int}
     */
    public function deliverPending(int $limit = 200): array
    {
        $pending = MessageDelivery::query()
            ->where('channel', MessageChannel::Mail)
            ->where('status', MessageDelivery::STATUS_PENDING)
            ->with(['message', 'user'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($pending as $delivery) {
            $message = $delivery->message;
            $user = $delivery->user;

            // The message or the person went away between writing the row and
            // reading it. Nothing to send, and nothing to retry for ever.
            if ($message === null || $user === null || ! $user instanceof User || $user->email === null) {
                $this->fail($delivery, 'No recipient address.');
                $failed++;

                continue;
            }

            try {
                Mail::to($user->email)->send(new CoordinatorMessage($message, $user->name));

                $delivery->forceFill([
                    'status' => MessageDelivery::STATUS_SENT,
                    'sent_at' => now(),
                    'error' => null,
                ])->save();

                $sent++;
            } catch (Throwable $e) {
                $this->fail($delivery, $e->getMessage());
                $failed++;

                // Worth a log line: one refused address is ordinary, and a
                // hundred of them is the mail server, which nothing on the
                // screen would otherwise say.
                Log::warning('Message delivery failed', [
                    'delivery_id' => $delivery->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Hand the owed notifications to whichever push service each device belongs
     * to, marking each row with what came of it.
     *
     * 🔴 One delivery row covers EVERY device that person has. The table keeps
     * one row per person per channel (a unique key says so), while a coordinator
     * may have a phone and a tablet — so the row is `sent` when at least one
     * device took it, and `failed` when none did. Anything else would need the
     * table to be about devices, and it is about people.
     *
     * 🪤 A subscription the service says is gone is DELETED rather than retried.
     * The application was removed, the browser data cleared or permission
     * withdrawn; left in place it is dialled on every message for ever and the
     * table fills with devices that stopped existing months ago.
     *
     * @return array{sent: int, failed: int, dropped: int}
     */
    public function deliverPushes(?int $limit = null): array
    {
        $limit ??= (int) config('push.batch', 100);

        $pending = MessageDelivery::query()
            ->where('channel', MessageChannel::Push)
            ->where('status', MessageDelivery::STATUS_PENDING)
            ->with(['message', 'user'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;
        $dropped = 0;

        foreach ($pending as $delivery) {
            $message = $delivery->message;

            if ($message === null || $delivery->user === null) {
                $this->fail($delivery, 'No recipient.');
                $failed++;

                continue;
            }

            $devices = PushSubscription::query()->where('user_id', $delivery->user_id)->get();

            if ($devices->isEmpty()) {
                /*
                 * Not a failure of sending — nobody turned notifications on, or
                 * they turned them off again. The row says so in its own words
                 * so that a screen counting failures does not report the
                 * administration's push channel as broken when it is merely
                 * unused.
                 */
                $this->fail($delivery, 'No device is subscribed.');
                $failed++;

                continue;
            }

            $payload = [
                'title' => $message->subject,
                'body' => $message->body,
                // Where the tap lands: their own notices, which is the one
                // screen that can show the message again afterwards.
                'url' => '/app/messages',
                // One message replaces its own earlier notification rather than
                // stacking a second copy on the lock screen.
                'tag' => 'message-'.$message->id,
            ];

            $reached = 0;

            foreach ($devices as $device) {
                $outcome = $this->push->send($device, $payload);

                if ($outcome === PushOutcome::Sent) {
                    $device->forceFill(['last_sent_at' => now()])->save();
                    $reached++;

                    continue;
                }

                if ($outcome === PushOutcome::Gone) {
                    $device->delete();
                    $dropped++;
                }
            }

            if ($reached > 0) {
                $delivery->forceFill([
                    'status' => MessageDelivery::STATUS_SENT,
                    'sent_at' => now(),
                    'error' => null,
                ])->save();

                $sent++;

                continue;
            }

            $this->fail($delivery, 'No device accepted it.');
            $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'dropped' => $dropped];
    }

    private function fail(MessageDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'status' => MessageDelivery::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
