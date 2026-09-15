<?php

declare(strict_types=1);

namespace App\Domain\Communication\Enums;

/**
 * Where a message is in its life.
 *
 * 🪤 `Sent` is about the message leaving, not about anyone reading it. What
 * reached whom is in `message_deliveries`, one row per person per channel.
 */
enum MessageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sent = 'sent';
}
