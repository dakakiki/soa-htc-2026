<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Getting back into an account nobody can remember the password for (ADR-0063).
 *
 * Two ways in reach this class and both end in the same mail to the same
 * address: the holder asks for it from the sign-in screen, or an administrator
 * sends it from the Users screen. Deliberately not two flows — an administrator
 * who could set a password would then have to tell somebody what it is, and a
 * password two people know is not a password.
 *
 * The broker does the parts that are easy to get wrong: a hashed single-use
 * token, an hour's expiry, one link a minute per address. What is written here
 * is only what the framework leaves open.
 */
final class PasswordRecovery
{
    /** The mailer itself refused. Not one of the broker's statuses. */
    public const MAILER_FAILED = 'passwords.mailer_failed';

    /**
     * Send a link, and report what the broker made of it.
     *
     * The status is for the caller who is allowed to know. The public form
     * throws it away and answers the same sentence to every address in the
     * world; the Users screen, where the account is already on display, reads it
     * — an administrator told "sent" about a mail the broker declined to send a
     * second time within the minute would go on waiting for it.
     *
     * Every path out of here looks identical from outside, which is the point.
     * `sendResetLink` answers with a status — sent, no such user, throttled — and
     * any of the three reaching the screen would turn the form into a way of
     * asking the site who has an account.
     *
     * 🪤 The mail failing is caught for the same reason rather than out of
     * tidiness. An SMTP server refusing a connection would only ever raise for an
     * address that exists, so an uncaught exception here is the leak coming back
     * in through the 500. It is logged, because a mailer that is down is
     * something the operator has to find out about some other way.
     */
    public static function offer(string $email): string
    {
        try {
            return Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('Password reset link could not be sent.', ['exception' => $e]);

            return self::MAILER_FAILED;
        }
    }

    /**
     * Everything that has to happen to an account when its password changes
     * under it, beyond the password itself.
     *
     * Somebody resetting a password may be doing it because somebody else knows
     * the old one. A new password that left the intruder's session open would
     * change nothing for as long as they kept clicking.
     */
    public static function signOutEverywhere(User $user): void
    {
        // "Keep me signed in" is a cookie the old token still validates.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        // Sessions live in the database (`SESSION_DRIVER=database`). Under any
        // other driver there is no table to sweep and nothing is lost — the
        // check is what keeps this from being a 500 on a site configured
        // differently, not an optional nicety.
        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
