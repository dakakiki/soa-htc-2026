<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

use App\Domain\Communication\Enums\MessageChannel;
use App\Domain\Communication\Enums\MessageStatus;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Models\MessageDelivery;
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

    public function __construct(private readonly RecipientResolver $recipients) {}

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

    private function fail(MessageDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'status' => MessageDelivery::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
