<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Communication\Models\Message;
use App\Mail\Concerns\KnowsTheSite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An administrator's message, in the post.
 *
 * The text is the one written in the administration and is not rewritten here:
 * the same words go to the app, to the mail and to a push notification, so a
 * coordinator who reads two of them reads the same thing twice rather than two
 * versions of it.
 *
 * 🔴 The body is plain text, escaped by the markdown mail. An administrator
 * types a notice, not markup, and a subject typed by a person must never be
 * able to put HTML into a stranger's inbox.
 */
class CoordinatorMessage extends Mailable
{
    use KnowsTheSite, Queueable, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.message.notice',
            with: [
                'name' => $this->recipientName,
                'siteName' => $this->siteName(),
                'body' => $this->hardBreaks($this->message->body),
                'loginUrl' => $this->loginUrl(),
            ],
        );
    }

    /**
     * Keep the line breaks the administrator typed.
     *
     * 🪤 A mail body is Markdown, and Markdown reads one newline as a space:
     * two lines typed in the box arrived in the inbox as one sentence, while
     * the same text in the app — where the notice honours newlines — stood on
     * two. One message, two readings of it.
     *
     * Two trailing spaces are Markdown's own hard break, so the break is asked
     * for in the language the mail is already written in rather than by putting
     * raw HTML into it. A blank line still starts a paragraph, as before.
     */
    private function hardBreaks(string $body): string
    {
        // Line endings first: a paste from Windows carries a carriage return,
        // and one left in front of the newline would sit inside the break.
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Single newlines only. A blank line is already a paragraph, and
        // padding it would leave a trailing break hanging above it.
        return preg_replace('/(?<!\n)\n(?!\n)/', "  \n", $body) ?? $body;
    }
}
