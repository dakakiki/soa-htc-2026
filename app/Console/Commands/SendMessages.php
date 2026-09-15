<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Support\MessageDispatcher;
use Illuminate\Console\Command;

/**
 * The two things a message needs after it has been written.
 *
 * First, a scheduled message whose hour has come is dispatched — which writes
 * every delivery and puts the in-app notice on the coordinators' screens.
 * Second, the mails owed by any message, this one or an earlier one, are handed
 * to the mailer in a batch small enough to finish.
 *
 * Safe to run on a schedule and safe to run twice: dispatching reads only
 * scheduled messages and closes each one it touches, and delivering reads only
 * pending rows and marks each one it touches.
 */
class SendMessages extends Command
{
    protected $signature = 'messages:send {--limit=200 : How many mails to hand over in one run}';

    protected $description = 'Dispatch scheduled messages to coordinators and send the mails they owe';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $due = Message::query()->due()->orderBy('send_at')->get();

        foreach ($due as $message) {
            $count = $dispatcher->dispatch($message);
            $this->info("Dispatched #{$message->id} \"{$message->subject}\" to {$count} coordinators.");
        }

        $result = $dispatcher->deliverPending((int) $this->option('limit'));

        if ($result['sent'] > 0 || $result['failed'] > 0) {
            $this->info("Mail: {$result['sent']} sent, {$result['failed']} failed.");
        }

        return self::SUCCESS;
    }
}
