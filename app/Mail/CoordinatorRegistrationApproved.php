<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your registration was approved — you can sign in." One of the two decision
 * mails (ADR-0053); see {@see CoordinatorRegistrationMail} for why both go to
 * the applicant.
 */
class CoordinatorRegistrationApproved extends CoordinatorRegistrationMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your coordinator account is open');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.coordinator-registration.approved',
            with: [
                'name' => $this->registration->name,
                'siteName' => $this->siteName(),
                'loginUrl' => $this->loginUrl(),
                'email' => $this->registration->email,
            ],
        );
    }
}
