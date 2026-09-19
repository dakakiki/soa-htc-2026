<?php

namespace Tests\Feature;

use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Support\LayoutZones;
use App\Domain\Communication\Models\Message;
use App\Domain\Communication\Support\MailBranding;
use App\Domain\Organization\Models\Setting;
use App\Mail\CoordinatorMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What every mail this application sends carries around its words (owner,
 * 2026-09-18): the logo and the title on the left over a brand rule, and under
 * the words a second rule, the administration's footer paragraph and the
 * copyright line the website already shows.
 *
 * 🔴 All of it administered. The logo is an upload under Settings -> Theme and
 * the footer's two texts are a layout block (ADR-0045) — the same block the
 * public footer draws, rather than a copy of its wording, so editing the site's
 * footer edits the mail's.
 */
class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_carries_the_title_over_a_brand_rule(): void
    {
        Setting::current()->update(['site_title' => '<p>Hippo the Contest</p>', 'color_palette_4' => '#012b44']);

        $html = $this->render();

        $this->assertStringContainsString('Hippo the Contest', $html);
        // Two rules: one under the header, one over the footer.
        $this->assertSame(2, substr_count($html, 'background-color: #012b44'));
    }

    /**
     * 🪤 `site_title` is rich text written for a masthead. Printed as stored, a
     * mail header would carry either a paragraph break or visible `<p>`
     * characters, depending on which client opened it.
     */
    public function test_the_title_is_not_the_markup_the_masthead_was_written_in(): void
    {
        Setting::current()->update(['site_title' => '<p>Hippo the Contest</p>', 'color_palette_4' => '#012b44']);

        $html = $this->render();

        $this->assertStringNotContainsString('<p>Hippo the Contest</p>', $html);
        // 🪤 And the header really drew it: an absent header contains no markup
        // either, so the first assertion alone holds with this file deleted.
        $this->assertSame('Hippo the Contest', $this->headerTitle($html));
    }

    /**
     * With nothing written in Settings, the application's own name stands.
     *
     * 🪤 Asked of the HEADER and not of the whole mail. Laravel's own default
     * header prints `config('app.name')` too, so a test looking for the name
     * anywhere in the document passes just as happily with this template deleted.
     */
    public function test_an_unwritten_title_falls_back_to_the_application_name(): void
    {
        config(['app.name' => 'SOA HTC']);
        Setting::current()->update(['site_title' => '', 'color_palette_4' => '#012b44']);

        $this->assertSame('SOA HTC', $this->headerTitle($this->render()));
    }

    /**
     * 🔴 Never an SVG. Gmail and Outlook do not draw one, so a vector logo is a
     * hole in the header for most of the people who receive the mail — and it is
     * not hypothetical: BOTH logos on the development installation are SVG and
     * only the icon is a PNG, which is how this was found.
     */
    public function test_a_vector_logo_is_passed_over_for_one_a_mail_client_can_draw(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo-dark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Storage::disk('public')->put('branding/icon.png', 'not-really-a-png');

        Setting::current()->update([
            'logo_dark_path' => 'branding/logo-dark.svg',
            'logo_path' => 'branding/logo.svg',
            'logo_icon_path' => 'branding/icon.png',
        ]);

        $html = $this->render();

        $this->assertStringNotContainsString('.svg', $html, 'a mail client would draw nothing where the logo is');
        $this->assertStringContainsString('branding/icon.png', $html);
    }

    /** And when everything on file is vector, the header stands on its title. */
    public function test_a_header_with_no_drawable_logo_is_a_title_and_not_a_gap(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Setting::current()->update([
            'logo_dark_path' => null,
            'logo_path' => 'branding/logo.svg',
            'logo_icon_path' => null,
            'site_title' => 'Hippo the Contest',
            'color_palette_4' => '#012b44',
        ]);

        $html = $this->render();

        $this->assertStringNotContainsString('<img', $html);
        // 🪤 Asked of the header. The site's name is also in the button under
        // the words, so looking for it anywhere passes with no header at all.
        $this->assertSame('Hippo the Contest', $this->headerTitle($html));
    }

    /**
     * 🔴 The footer's two texts come from the block the WEBSITE's footer draws.
     * A second wording kept in the mail would be right on the day it was written
     * and wrong from the first time somebody edited the site.
     */
    public function test_the_footer_says_what_the_site_footer_says(): void
    {
        $this->footerBlock([
            'text' => '<p>The English language contest for school students.</p>',
            'copyright' => '© {year} SOA HTC',
        ]);

        $html = $this->render();

        $this->assertStringContainsString('The English language contest for school students.', $html);
        $this->assertStringContainsString('© '.now()->year.' SOA HTC', $html);
    }

    /**
     * 🪤 `{year}` is substituted when the mail is drawn and never stored — a year
     * written into the record goes wrong on the first of January and stays wrong
     * for months with nobody looking at it.
     */
    public function test_the_year_is_the_year_the_mail_is_sent(): void
    {
        /*
         * 🪤 A line Laravel's own footer could not have produced. Its default is
         * "© <year> <app name>. All rights reserved.", so a test written around
         * "© 2026 SOA HTC" passes with this template deleted — the framework
         * prints those very characters.
         */
        $this->footerBlock(['copyright' => '© {year} Hippo · every venue']);

        $html = $this->render();

        // Both halves: a mail that never printed the line at all also contains
        // no `{year}`, so the absence on its own proves nothing.
        $this->assertStringContainsString('© '.now()->year.' Hippo · every venue', $html);
        $this->assertStringNotContainsString('{year}', $html);
    }

    /**
     * 🔴 Laravel's own footer line is gone, not merely unused. Left in place it
     * would print a second copyright, in the framework's words, under the
     * administration's own.
     */
    public function test_the_frameworks_default_footer_line_does_not_also_appear(): void
    {
        $this->footerBlock(['copyright' => '© {year} SOA HTC']);

        $html = $this->render();

        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertSame(1, substr_count($html, '© '.now()->year.' SOA HTC'));
    }

    /** A footer nobody has written yet leaves the rule and nothing under it. */
    public function test_a_mail_still_sends_when_the_footer_has_never_been_written(): void
    {
        $this->assertStringContainsString('Entry closes', $this->render());
    }

    /**
     * 🪤 One pair of queries per request, however many people are written to. The
     * two mail views resolve this out of the container on EVERY render, and a
     * message to four hundred coordinators is four hundred renders.
     */
    public function test_the_branding_is_read_once_and_not_once_per_recipient(): void
    {
        $this->footerBlock(['copyright' => '© {year} SOA HTC']);

        $brand = app(MailBranding::class);
        $brand->title();
        $brand->copyright();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        // 🔴 Resolved again, as the second render would: a singleton, or this
        // counts the queries of a fresh instance and proves nothing.
        $again = app(MailBranding::class);
        $again->title();
        $again->logoUrl();
        $again->rule();
        $again->copyright();
        $again->footerText();

        $this->assertSame(0, $queries, 'the header is rendered once per recipient; it must not ask again');
    }

    // ---- the template's own settings (ADR-0132) ----

    /**
     * 🔴 The reason the field exists. Both of the site's logos may be vectors,
     * which a mail cannot draw at all, so the mail's own upload is the only
     * raster in the building — and it has to beat them.
     */
    public function test_the_mails_own_logo_beats_the_ones_the_site_uploaded(): void
    {
        Setting::current()->update([
            'logo_dark_path' => 'branding/site-dark.png',
            'mail_logo_path' => 'branding/for-the-mail.png',
        ]);

        $html = $this->render();

        $this->assertStringContainsString('for-the-mail.png', $html);
        $this->assertStringNotContainsString('site-dark.png', $html);
    }

    /** 🪤 And the skip still applies to it: an SVG here is a hole for most readers. */
    public function test_a_vector_uploaded_for_the_mail_is_skipped_like_any_other(): void
    {
        Setting::current()->update([
            'logo_path' => 'branding/site.png',
            'mail_logo_path' => 'branding/for-the-mail.svg',
        ]);

        $html = $this->render();

        $this->assertStringNotContainsString('for-the-mail.svg', $html);
        $this->assertStringContainsString('site.png', $html);
    }

    public function test_the_mails_own_header_text_beats_the_site_title(): void
    {
        Setting::current()->update([
            'site_title' => '<p>Hippo the Contest</p>',
            'mail_header_text' => 'Hippo — official mail',
            'color_palette_4' => '#012b44',
        ]);

        $this->assertSame('Hippo — official mail', $this->headerTitle($this->render()));
    }

    /** Untouched, the letter opens and closes exactly as it did before any of this. */
    public function test_an_untouched_template_greets_and_signs_off_the_way_it_always_did(): void
    {
        Setting::current()->update(['site_title' => 'Hippo the Contest']);

        $html = $this->render();

        $this->assertStringContainsString('Hello Ana,', $html);
        $this->assertStringContainsString('Thanks,', $html);
        $this->assertStringContainsString('Hippo the Contest', $html);
    }

    public function test_the_greeting_and_the_sign_off_are_the_administrations_words(): void
    {
        Setting::current()->update([
            'site_title' => 'Hippo the Contest',
            'mail_greeting' => 'Dear {name},',
            'mail_signoff' => "Warm regards,\nThe {site} team",
        ]);

        $html = $this->render();

        $this->assertStringContainsString('Dear Ana,', $html);
        $this->assertStringNotContainsString('Hello Ana,', $html);
        // 🪤 The newline has to survive as a break, or the sign-off is one line.
        $this->assertStringContainsString('Warm regards,<br>', $html);
        $this->assertStringContainsString('The Hippo the Contest team', $html);
    }

    public function test_the_footer_address_and_mailbox_are_printed_and_linked(): void
    {
        Setting::current()->update([
            'mail_footer_web' => 'soa-htc.org',
            'mail_footer_email' => 'info@soa-htc.org',
        ]);

        $html = $this->render();

        // Typed without a scheme, linked with one.
        $this->assertStringContainsString('href="https://soa-htc.org"', $html);
        $this->assertStringContainsString('href="mailto:info@soa-htc.org"', $html);
    }

    public function test_nothing_is_printed_where_no_address_was_given(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('mailto:', $html);
    }

    /**
     * 🔴 The promise ADR-0126 made, kept. The field is an override: filled it
     * wins, emptied the website's own footer comes back — so the two can only
     * differ because somebody decided they should.
     */
    public function test_the_footer_paragraph_is_an_override_of_the_websites_own(): void
    {
        $this->footerBlock(['text' => 'Written on the website.']);

        $this->assertStringContainsString('Written on the website.', $this->render());

        Setting::current()->update(['mail_footer_text' => 'Written for the letter.']);
        $html = $this->render();
        $this->assertStringContainsString('Written for the letter.', $html);
        $this->assertStringNotContainsString('Written on the website.', $html);

        Setting::current()->update(['mail_footer_text' => '']);
        $this->assertStringContainsString('Written on the website.', $this->render());
    }

    /**
     * The words beside the logo: the text of the span the header draws in the
     * brand colour. Null when this template is not the one that rendered.
     */
    private function headerTitle(string $html): ?string
    {
        $colour = Setting::current()->color_palette_4;

        return preg_match('/color: '.preg_quote($colour, '/').';">([^<]+)</', $html, $m) === 1
            ? trim($m[1])
            : null;
    }

    /** @param array<string, mixed> $data */
    private function footerBlock(array $data): void
    {
        LayoutBlock::create([
            'zone' => LayoutZones::PUBLIC_FOOTER,
            'type' => BlockType::Footer,
            'position' => 1,
            'status' => true,
            'data' => $data,
        ]);
    }

    private function render(): string
    {
        // 🪤 A fresh reader per render. MailBranding is a singleton that memoises
        // the settings row — right for one send, where four hundred headers must
        // not be four hundred queries, and wrong for a test that renders, changes
        // a setting and renders again. Each call here stands for a separate send.
        app()->forgetInstance(MailBranding::class);

        $message = new Message([
            'subject' => 'Entry closes on 20 September',
            'body' => 'Every child needs a candidate number.',
            'body_html' => null,
        ]);

        return (new CoordinatorMessage($message, 'Ana'))->render();
    }
}
