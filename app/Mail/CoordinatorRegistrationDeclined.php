<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your registration was not approved." The second decision mail (ADR-0053).
 *
 * 🪤 It does NOT carry `decline_reason`. That field is a note between reviewers,
 * written in the knowledge that reviewers read it; forwarding it to the applicant
 * would publish an internal judgement about a named school, and would quietly
 * change what a reviewer can safely write down. The applicant is given the
 * decision and an address to write to.
 */
class CoordinatorRegistrationDeclined extends CoordinatorRegistrationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'About your coordinator registration');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.coordinator-registration.declined',
            with: [
                'name' => $this->registration->name,
                'siteName' => $this->siteName(),
                'contactAddress' => (string) config('mail.from.address'),
            ],
        );
    }
}
