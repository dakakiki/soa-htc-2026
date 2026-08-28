<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\KnowsTheSite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one mail that acts on its own (ADR-0063).
 *
 * Every other mail this application sends reports something that has already
 * happened — an approval, a refusal — and carries no token, on purpose. This one
 * is the exception, because a forgotten password cannot be recovered by being
 * told about it. What limits the exception is the token itself: it is single-use,
 * it expires in an hour, and until it is spent the account is untouched.
 *
 * It is a Mailable rather than Laravel's `ResetPassword` notification so that it
 * speaks in the site's own name and in the same voice as the coordinator mails
 * beside it. The framework's version says "Reset Password Notification" from
 * `APP_NAME`, which on a site called Hippo the Contest is a mail from nobody.
 */
class PasswordResetLink extends Mailable
{
    use KnowsTheSite, Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        /** The broker's token. Never stored, never logged, never shown again. */
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set a new password');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset.link',
            with: [
                'name' => $this->user->name,
                'siteName' => $this->siteName(),
                'email' => $this->user->email,
                'resetUrl' => $this->resetUrl(),
                // Said in the mail because the reader decides when to open it.
                // A link that dies without having said it would was going to
                // reads as a broken site rather than as an expired link.
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ],
        );
    }

    /**
     * Where the link lands.
     *
     * The token travels in the path and the address in the query, which is the
     * shape Laravel's own reset route uses — the screen at the other end is a
     * screen of ours, but the two halves it needs are the broker's, so they are
     * carried the way the broker expects to get them back.
     */
    private function resetUrl(): string
    {
        return $this->siteUrl('reset-password/'.$this->token).'?email='.urlencode($this->user->email);
    }
}
