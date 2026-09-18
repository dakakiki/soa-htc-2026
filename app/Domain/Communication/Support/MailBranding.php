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

        foreach ([$setting?->logo_dark_path, $setting?->logo_path, $setting?->logo_icon_path] as $path) {
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

    /** The paragraph under the footer rule — admin-authored markup, as on the site. */
    public function footerText(): ?string
    {
        $text = trim((string) ($this->footerBlock()['text'] ?? ''));

        return $text !== '' ? $text : null;
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
