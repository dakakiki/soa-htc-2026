<?php

declare(strict_types=1);

namespace App\Domain\Communication\Enums;

/**
 * The ways a message can travel.
 *
 * 🔴 `App` is always among them and cannot be switched off — and since
 * 2026-09-18 that is ENFORCED rather than merely said here. It is the only
 * channel that needs no address, no permission and no third party, so it is the
 * one that always arrives; and it is the only one that KEEPS the message. Mail
 * leaves the building, and a notification is gone the moment it is swiped away.
 *
 * 🪤 This docblock made that claim from the day it was written while the compose
 * screen offered a checkbox that contradicted it. Four messages went out by push
 * alone: they arrived on a phone and pointed at an inbox with nothing in it.
 * `MessageController::validated()` now adds this channel to whatever was asked
 * for, because a rule that lives in a form is a rule until somebody posts JSON.
 *
 * Mail and push are what the administration adds when the message should also
 * find somebody who is not looking at the app.
 */
enum MessageChannel: string
{
    case App = 'app';
    case Mail = 'mail';
    case Push = 'push';
}
