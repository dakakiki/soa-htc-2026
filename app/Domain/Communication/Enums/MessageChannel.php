<?php

declare(strict_types=1);

namespace App\Domain\Communication\Enums;

/**
 * The ways a message can travel.
 *
 * `App` is always among them and cannot be switched off: it is the only
 * channel that needs no address, no permission and no third party, so it is
 * the one that always arrives. Mail and push are what the administration adds
 * when the message should also find someone who is not looking at the app.
 */
enum MessageChannel: string
{
    case App = 'app';
    case Mail = 'mail';
    case Push = 'push';
}
