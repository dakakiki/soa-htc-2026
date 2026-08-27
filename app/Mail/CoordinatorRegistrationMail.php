<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Organization\Models\CoordinatorRegistration;
use App\Domain\Organization\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * What the two decision e-mails share (ADR-0053).
 *
 * There are exactly two, and both go to the APPLICANT. That is the correction
 * to the legacy app, where the one mail it sent — the account activation link —
 * went to `venue@hippo-thecontest.org`, the organisation's own address. Nobody
 * ever told the coordinator anything: an administrator clicked the link in the
 * shared inbox and the applicant found out by trying to sign in.
 *
 * Neither mail carries a password, a token or a link that acts on its own. The
 * approval is made in the admin screen; the mail only reports it.
 */
abstract class CoordinatorRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly CoordinatorRegistration $registration) {}

    /**
     * The site's own name, as the theme editor set it, so the mail matches the
     * site the applicant registered on rather than the framework's app name.
     */
    protected function siteName(): string
    {
        $title = trim(strip_tags((string) (Setting::current()->site_title ?? '')));

        return $title !== '' ? $title : (string) config('app.name', 'SOA HTC');
    }

    /** Where the applicant signs in, once there is anything to sign in to. */
    protected function loginUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/login';
    }
}
