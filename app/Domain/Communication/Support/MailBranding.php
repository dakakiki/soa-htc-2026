<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Support\LayoutZones;
use App\Domain\Organization\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What every mail this application sends carries around its words: the logo and
 * the title above them, and the administration's own footer under them.
 *
 * 🔴 Administered, like everything else with a colour or a logo on it. The
 * masthead and the footer of the website are not written in the code — the logo
 * is uploaded under Settings -> Theme and the footer's paragraph and copyright
 * line are a layout block (ADR-0045) — and a mail that hard-coded either would
 * be the one place the administration cannot reach. It would also go stale
 * silently, which is the worst way for branding to be wrong.
 *
 * 🪤 Every read is wrapped. A mail is often sent from a queue worker or a cron
 * run, where a database that will not answer must not turn one unsendable mail
 * into a failed job — the letter is still worth sending without its logo. The
 * same reasoning as the shell in `SpaController`.
 *
 * 🪤 Resolved ONCE per request and held. Sending to four hundred coordinators
 * renders four hundred copies of this header, and without the cache that is four
 * hundred pairs of queries for a logo that cannot have changed in between.
 */
class MailBranding
{
    /**
     * What the letter opened and closed with before any of it was editable, kept
     * as the default so an untouched installation sends the same mail it did.
     */
    public const DEFAULT_GREETING = 'Hello {name},';

    public const DEFAULT_SIGNOFF = "Thanks,\n{site}";

    private ?Setting $setting = null;

    /** @var array<string, mixed>|null */
    private ?array $footer = null;

    private bool $footerLoaded = false;

    /** The brand navy — the rule under the header and over the footer. */
    public function rule(): string
    {
        return $this->setting()?->color_palette_4 ?? '#003758';
    }

    /**
     * The logo for a light ground, which is what a mail is.
     *
     * 🪤 The DARK logo first, and the others after it rather than nothing. The
     * website draws `logo_dark` on its own pale page and `logo` on the navy
     * footer; a mail is the first, and an installation that uploaded only one
     * logo is better served by it than by a gap.
     *
     * 🔴 And never an SVG. Gmail and Outlook do not draw one — they show the
     * alt text, or nothing at all — so a vector logo in a mail is a hole in the
     * header for most of the people who receive it. The dev installation's own
     * logo is an SVG, which is how this was noticed rather than reported. The
     * raster icon is tried next, and if everything on file is vector the header
     * simply stands on its title, which is a header rather than a gap.
     */
    public function logoUrl(): ?string
    {
        $setting = $this->setting();

        // 🔴 The mail's OWN upload first (ADR-0132). It is the field that exists
        // because of the rule below: an installation whose logos are all vector
        // has nowhere else to put a raster, and this is that nowhere else.
        $candidates = [$setting?->mail_logo_path, $setting?->logo_dark_path, $setting?->logo_path, $setting?->logo_icon_path];

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && ! $this->isVector($path)) {
                return Storage::disk('public')->url($path);
            }
        }

        return null;
    }

    private function isVector(string $path): bool
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * The name beside the logo: what the COMPETITION calls itself.
     *
     * 🪤 `site_title` is rich text an administrator wrote for a masthead, so it
     * arrives as markup and is stripped rather than printed — a mail header is
     * one line, and `<p>` tags in it would be either a paragraph break or
     * visible characters depending on the client.
     */
    public function title(): string
    {
        $title = trim(html_entity_decode(strip_tags((string) $this->setting()?->site_title), ENT_QUOTES | ENT_HTML5));

        return $title !== '' ? $title : (string) config('app.name', 'SOA HTC');
    }

    /**
     * The name printed beside the logo.
     *
     * The mail's own wording when one is set, otherwise the site's — a masthead
     * that has never been given words is still the competition's name.
     */
    public function headerText(): string
    {
        $own = trim((string) $this->setting()?->mail_header_text);

        return $own !== '' ? $own : $this->title();
    }

    /**
     * The line the letter opens with, addressed to $name.
     *
     * 🪤 `{name}` is substituted here and the whole line is the administrator's,
     * so a greeting that does not use a name (or one written in a language that
     * puts it elsewhere) is a matter of typing it that way.
     */
    public function greeting(?string $name): ?string
    {
        $line = trim((string) ($this->setting()?->mail_greeting ?? ''));

        if ($line === '') {
            $line = self::DEFAULT_GREETING;
        }

        return trim(str_replace('{name}', trim((string) $name), $line)) ?: null;
    }

    /**
     * The line it closes with. `{site}` is the competition's name.
     *
     * Plain text rather than markup: it is two short lines at the end of a
     * letter, and the view turns its newlines into breaks.
     */
    public function signOff(): ?string
    {
        $text = trim((string) ($this->setting()?->mail_signoff ?? ''));

        if ($text === '') {
            $text = self::DEFAULT_SIGNOFF;
        }

        return trim(str_replace('{site}', $this->title(), $text)) ?: null;
    }

    /**
     * The paragraph under the footer rule — admin-authored markup, as on the site.
     *
     * 🔴 An OVERRIDE, not a replacement (ADR-0132). Left empty this still reads
     * the website's own footer block, which is the promise ADR-0126 made: one
     * wording, edited in one place, never drifting from the site. The field
     * exists for the installation that wants the letter to say something the
     * page does not — and the day it is cleared, the site's words come back.
     */
    public function footerText(): ?string
    {
        $own = trim((string) ($this->setting()?->mail_footer_text ?? ''));

        if ($own !== '') {
            return $own;
        }

        $text = trim((string) ($this->footerBlock()['text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    /** The website address printed in the foot of the letter, if one is set. */
    public function footerWeb(): ?string
    {
        return trim((string) ($this->setting()?->mail_footer_web ?? '')) ?: null;
    }

    /** The address a recipient can write back to, if one is set. */
    public function footerEmail(): ?string
    {
        return trim((string) ($this->setting()?->mail_footer_email ?? '')) ?: null;
    }

    /**
     * The address as a link. A bare `soa-htc.org` is what an administrator types
     * and not something a mail client will make clickable, so the scheme is put
     * back on for the href while the printed text stays as typed.
     */
    public function footerWebUrl(): ?string
    {
        $web = $this->footerWeb();

        if ($web === null) {
            return null;
        }

        return preg_match('~^https?://~i', $web) === 1 ? $web : 'https://'.$web;
    }

    /**
     * The line under it.
     *
     * 🪤 `{year}` is substituted when the mail is drawn and never stored, for
     * the same reason the website substitutes it when the page is drawn: a year
     * written into the record goes wrong on the first of January and stays wrong
     * for months with nobody looking at it.
     */
    public function copyright(): ?string
    {
        $line = trim((string) ($this->footerBlock()['copyright'] ?? ''));

        return $line !== '' ? str_replace('{year}', (string) now()->year, $line) : null;
    }

    private function setting(): ?Setting
    {
        try {
            return $this->setting ??= Setting::current();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function footerBlock(): array
    {
        if ($this->footerLoaded) {
            return $this->footer ?? [];
        }

        $this->footerLoaded = true;

        try {
            $this->footer = LayoutBlock::query()
                ->inZone(LayoutZones::PUBLIC_FOOTER)
                ->enabled()
                ->value('data') ?? [];
        } catch (Throwable) {
            $this->footer = [];
        }

        return $this->footer ?? [];
    }
}
