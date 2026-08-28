<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Domain\Organization\Models\Setting;

/**
 * What every mail the application sends has to agree on: whose site it comes
 * from, and where on that site the reader is being pointed.
 *
 * Both answers were written once for the coordinator decision mails (ADR-0053)
 * and were needed a second time by the password recovery mail. Copied, they
 * would have drifted the first time somebody renamed the site — one mail saying
 * "Hippo the Contest" and the next saying "SOA HTC" about the same address.
 */
trait KnowsTheSite
{
    /**
     * The site's own name, as the theme editor set it, so the mail matches the
     * site the reader knows rather than the framework's app name.
     */
    protected function siteName(): string
    {
        $title = trim(strip_tags((string) (Setting::current()->site_title ?? '')));

        return $title !== '' ? $title : (string) config('app.name', 'SOA HTC');
    }

    /** An address on the public site, from a path the SPA answers. */
    protected function siteUrl(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** Where anybody with an account signs in. */
    protected function loginUrl(): string
    {
        return $this->siteUrl('login');
    }
}
