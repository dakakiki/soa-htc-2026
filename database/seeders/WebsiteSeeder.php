<?php

namespace Database\Seeders;

use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\LayoutZones;
use Illuminate\Database\Seeder;

/**
 * The public site itself: its sections, its navigations and the one page the
 * footer links to.
 *
 * This is NOT development sample data, which is why it left {@see MasterDataSeeder}
 * on 2026-08-27. That seeder is synthetic dev material - invented schools, a dev
 * administrator, three countries - and it is barred from anything but `local`
 * and `testing`. The site's own structure was nested inside it and barred twice
 * over, so a fresh production came up with an empty front page, no menus, and a
 * footer missing the item it was written to carry. Nothing here is invented data:
 * it is the arrangement the code used to hard-code before ADR-0043/0045 made it
 * editable, so a fresh install looks like the design and an admin can change it
 * without a commit.
 *
 * IMAGES ARE DELIBERATELY NOT SEEDED. The owner refused to commit the photographs
 * and the logo (2026-08-25), so `mediaId()` finds nothing outside a dev library
 * and the blocks are created with no image at all. Every section renders without
 * one - the hero and the coordinator band simply lose their photograph - and the
 * category document button, whose target is a file nobody has uploaded, is
 * dropped by LayoutButtons rather than published as a dead link. On production
 * the pictures are uploaded once through Website -> Media and Settings -> Theme.
 *
 * Every method fills only what is empty. An arranged page belongs to the admin,
 * not to the seeder, so this can be re-run after a deploy without undoing anybody.
 *
 * The environment decision is NOT here but in {@see DatabaseSeeder}: this has to
 * stay callable from a test that wants a whole site to look at, while the default
 * `$this->seed()` leaves the layout empty for the tests that build their own.
 */
class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCookiePolicy();
        $this->seedMenus();
        $this->seedHomeLayout();
        $this->seedChromeLayout();
    }

    /**
     * The cookie policy, which the footer menu has always linked and nothing has
     * ever created (2026-08-27).
     *
     * `seedMenus()` reads this page by slug and skips its menu item when the page
     * is missing, so the footer came up with three items instead of four - on
     * production and locally alike. It runs first for that reason.
     *
     * The body is an unmistakable placeholder rather than invented legal text: a
     * policy is the venue's to write, and copy that reads like one would be worse
     * than an obvious gap. Published, so the link works and whoever deploys sees
     * immediately that it needs replacing.
     */
    private function seedCookiePolicy(): void
    {
        Page::query()->firstOrCreate(
            ['slug' => 'cookie-policy'],
            [
                'title' => 'Cookie Policy',
                'body' => '<p><strong>This page has not been written yet.</strong> It is a placeholder'
                    .' created when the site was installed, so that the footer link has somewhere to go.</p>'
                    .'<p>Replace this text with the cookie policy from Website &rarr; Pages.</p>',
                'status' => PublicationStatus::Published,
                'published_at' => now(),
            ],
        );
    }

    /**
     * The header and the footer as records (ADR-0045), and the copy of the screens
     * the application draws itself (ADR-0046).
     *
     * Before this the header drew `public-header` and the footer drew both menus
     * by handle, hard-coded in the shell, with its paragraph and both column
     * headings sitting in `en.ts`. Now the choice is data, and the seeder makes
     * the same choice the code used to make — so a fresh local site looks exactly
     * as it did, and an admin can change it without a commit.
     *
     * Each zone holds one block, filled only when it is empty: a header an admin
     * has already pointed somewhere else is not the seeder's to correct.
     */
    private function seedChromeLayout(): void
    {
        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_LOGIN)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_LOGIN,
                'type' => BlockType::Login,
                'status' => true,
                'position' => 1,
                'data' => [
                    'eyebrow' => 'Staff access',
                    'title' => 'Sign in',
                    'lead' => '<p>For administrators and coordinators. Competitors do not sign in —'
                        .' they enter with their candidate number.</p>',
                    // Both halves of what legacy said under its form: who the
                    // screen is for, and where everybody else goes (ADR-0053).
                    // 🪤 "Register as a coordinator", never "register your school"
                    // (owner, 2026-08-27). What the form creates is a coordinator
                    // account; the venue is attached afterwards by an administrator
                    // (ADR-0053). Copy that says "school" promises a screen that
                    // does not exist.
                    'aside' => '<p>For registered venues only. Not registered yet?'
                        .' <a href="/register">Register as a coordinator</a>.</p>',
                ],
            ]);
        }

        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_REGISTER)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_REGISTER,
                'type' => BlockType::Register,
                'status' => true,
                'position' => 1,
                'data' => [
                    'title' => 'Register as a coordinator',
                    // 🪤 The approved design ended this with "You register your
                    // venue after that." There is no such screen and there is not
                    // going to be one in this round — the owner's rule
                    // (2026-08-26) is that an administrator attaches the venue,
                    // exactly as in legacy. Copy that promises a step nobody
                    // built is worse than copy that says less.
                    'lead' => '<p>Your registration is sent straight away, but no account is opened'
                        .' until an administrator has approved it.</p>',
                    'document_note' => '<p>Download the approval form, have it signed by the venue,'
                        .' then attach it below.</p>',
                    // No target yet: the form itself is a document somebody has
                    // to upload to the media library. Until then LayoutButtons
                    // drops the button rather than publishing a dead link.
                    'button' => self::button('Approval form', 'file', 'link'),
                    'sent_title' => 'With the administrators',
                    'sent_lead' => '<p>We have your registration and your signed approval. An administrator'
                        .' reviews it and opens your account — you will get an e-mail either way.</p>',
                    'sent_note' => '<p>Something wrong with what you sent? Write to'
                        .' <a href="mailto:venue@hippo-thecontest.org">venue@hippo-thecontest.org</a>.</p>',
                ],
            ]);
        }

        // The competitor entry screen, once per stream. Each note points at the
        // other way in, which is the only place either is mentioned.
        $identify = [
            LayoutZones::PUBLIC_IDENTIFY_COMPETITION => [
                'eyebrow' => 'Competition entry',
                'title' => 'Start your quiz',
                'lead' => '<p>Three things off your candidate card, and the password your invigilator'
                    .' reads out. No account, no sign-in.</p>',
                'aside' => '<p>Just practising? <a href="/student/access/sample">Try a sample exam</a>'
                    .' — no password needed.</p>',
            ],
            LayoutZones::PUBLIC_IDENTIFY_RESULTS => [
                'eyebrow' => 'Check results',
                'title' => 'Look up your marks',
                'lead' => '<p>The same three things off your candidate card. No password — this only shows'
                    .' what you have already sat.</p>',
                'aside' => '<p>A test you have sat but cannot see yet is still with the administrators.</p>',
            ],
            LayoutZones::PUBLIC_IDENTIFY_SAMPLE => [
                'eyebrow' => 'Sample exam',
                'title' => 'Practise first',
                'lead' => '<p>The same three things off your candidate card. Nothing you answer here'
                    .' counts towards the contest.</p>',
                'aside' => '<p>Sitting the real thing? <a href="/student/access/competition">Start your'
                    .' quiz</a> — your invigilator reads out the password.</p>',
            ],
        ];

        foreach ($identify as $zone => $data) {
            if (! LayoutBlock::query()->where('zone', $zone)->exists()) {
                LayoutBlock::query()->create([
                    'zone' => $zone,
                    'type' => BlockType::Identify,
                    'status' => true,
                    'position' => 1,
                    'data' => $data,
                ]);
            }
        }

        $headerMenu = Menu::query()->where('slug', 'public-header')->value('id');
        $footerMenu = Menu::query()->where('slug', 'public-footer')->value('id');

        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_HEADER)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_HEADER,
                'type' => BlockType::Header,
                'status' => true,
                'position' => 1,
                'data' => ['menu' => $headerMenu],
            ]);
        }

        if (! LayoutBlock::query()->where('zone', LayoutZones::PUBLIC_FOOTER)->exists()) {
            LayoutBlock::query()->create([
                'zone' => LayoutZones::PUBLIC_FOOTER,
                'type' => BlockType::Footer,
                'status' => true,
                'position' => 1,
                'data' => [
                    'text' => '<p>The English language contest for school students,'
                        .' run with local venues and coordinators.</p>',
                    'columns' => [
                        ['title' => 'User services', 'menu' => $headerMenu],
                        ['title' => 'Privacy centre', 'menu' => $footerMenu],
                    ],
                    'copyright' => '© {year} SOA HTC',
                ],
            ]);
        }
    }

    /**
     * The front page as designed (ADR-0043): the sections in the order a visitor
     * meets them. Only filled when the zone is empty — an arranged page belongs
     * to the admin, not to the seeder.
     *
     * Two buttons are deliberately seeded without a destination: the category
     * document has not been uploaded yet, and coordinator registration has no
     * page. Their switches are on, so they appear in the editor waiting for a
     * target, while the public side drops them rather than publishing a link
     * that goes nowhere.
     */
    private function seedHomeLayout(): void
    {
        $zone = LayoutZones::PUBLIC_HOME;

        if (LayoutBlock::query()->where('zone', $zone)->exists()) {
            return;
        }

        $blocks = [
            [BlockType::Hero, 'soa-img-1', [
                'eyebrow' => '01 — Live exams',
                'title_accent' => 'LIVE',
                'title' => 'Hippo Exams',
                'lead' => 'For the Contest purposes only — the live preliminary and national finals exams.'
                    .' Your candidate number and date of birth get you in, nothing else to remember.',
                'buttons' => [
                    self::button('Start now', 'route', 'primary', value: '/student/access/competition', gate: 'competition',
                        closedNote: 'Live exams open when the round starts'),
                    self::button('Try a sample exam', 'route', 'link', value: '/student/access/sample', gate: 'sample',
                        closedNote: 'No sample test is published just now'),
                ],
            ]],
            [BlockType::Notice, null, [
                'title' => 'No double entries',
                'rules' => [
                    ['marker' => 'A', 'text' => 'No logging in on two devices at the same time — PC and tablet, or a phone.'],
                    ['marker' => 'B', 'text' => 'No second window or tab while the exam is running.'],
                ],
                'footnote' => 'Breaking either rule logs the candidate out automatically,'
                    .' and they will not be able to log in again.',
            ]],
            [BlockType::Category, null, [
                'eyebrow' => '02 — Categories',
                'title' => 'Your Hippo category',
                'lead' => 'The Hippo Category document holds two tables, for two groups of countries.'
                    .' Check which one applies before you enter.',
                'groups' => [
                    ['numeral' => '6', 'title' => 'Primary starts at six', 'text' => 'Countries where primary education begins at the age of 6.'],
                    ['numeral' => '7', 'title' => 'Primary starts at seven', 'text' => 'Countries where primary education begins at the age of 7.'],
                ],
                'buttons' => [
                    self::button('Download the category document', 'file', 'navy'),
                ],
            ]],
            [BlockType::SplitCta, null, [
                'eyebrow' => '03 — Practice & results',
                'columns' => [
                    [
                        'accent' => 'primary',
                        'title' => 'Start your practice test',
                        'note' => 'Registered candidates only',
                        'text' => 'CEFR aligned tests in sample mode — no exam password, and as much practice'
                            .' as you want before the live round.',
                        'button' => self::button('Start practice', 'route', 'navy', value: '/student/access/sample', gate: 'sample',
                            closedNote: 'No sample test is published just now'),
                    ],
                    [
                        'accent' => 'amber',
                        'title' => 'Check your results',
                        'note' => 'Open to all candidates',
                        'text' => 'Prepare your candidate number and date of birth. Available results:'
                            .' sample test, Preliminary Round and National Finals.',
                        'button' => self::button('Check results', 'route', 'navy', value: '/student/access/results'),
                    ],
                ],
            ]],
            [BlockType::Coordinators, 'soa-img-3', [
                'eyebrow' => '04 — For venues',
                'title' => 'Coordinator access',
                'lead' => 'Coordinators sign in with their e-mail and password to enter students, print'
                    .' attendance registers and follow their venue\'s results. New coordinators register first.',
                'buttons' => [
                    self::button('Coordinator login', 'route', 'amber', value: '/login'),
                    // Seeded without a destination when the home page was built,
                    // waiting for the screen behind it. It exists now (ADR-0053) —
                    // and it registers a COORDINATOR, not a school.
                    self::button('Register as a coordinator', 'route', 'link', value: '/register'),
                ],
            ]],
            [BlockType::Contact, null, [
                'title' => 'Have questions?',
                'lead' => 'Everything about the contest itself lives on the Hippo website. Venues that want'
                    .' to host an exam write to us directly.',
                'links' => [
                    ['label' => 'Contest website', 'value' => 'www.hippo-thecontest.org', 'url' => 'https://www.hippo-thecontest.org'],
                    ['label' => 'Host a venue', 'value' => 'venue@hippo-thecontest.org', 'url' => 'mailto:venue@hippo-thecontest.org'],
                ],
            ]],
            [BlockType::News, null, [
                'title' => 'Latest news',
                'limit' => 3,
            ]],
        ];

        foreach ($blocks as $position => [$type, $image, $data]) {
            LayoutBlock::query()->create([
                'zone' => $zone,
                'type' => $type,
                'position' => $position + 1,
                'status' => true,
                'image_media_id' => $image === null ? null : self::mediaId($image),
                'data' => $data,
            ]);
        }
    }

    /**
     * One button in the shape `BlockSchema` validates. A target left empty is
     * intentional: the section shows in the editor, the public page does not
     * render a link to nowhere.
     *
     * @return array<string, mixed>
     */
    private static function button(
        string $label,
        string $targetType,
        string $style,
        ?string $value = null,
        ?string $gate = null,
        ?string $closedNote = null,
    ): array {
        return [
            'label' => $label,
            'style' => $style,
            'status' => true,
            'gate' => $gate,
            // What stands here once the gate closes. Every gated button gets
            // one: a gate with nothing to say leaves a hole in the section and
            // the visitor cannot tell a shut contest from a broken page.
            //
            // The two gates are NOT the same kind of fact and their lines must
            // not be written as though they were (owner, 2026-08-27: practice
            // is always available and has nothing to do with the round). The
            // competition gate really is the season. The sample gate only asks
            // whether a sample test is published - an administrative state, and
            // one that is meant to be true all year - so its line says that and
            // never promises a round.
            'closed_note' => $closedNote,
            'target' => ['type' => $targetType, 'id' => null, 'value' => $value],
        ];
    }

    /** The library image with this basename, when the dev library holds it. */
    private static function mediaId(string $basename): ?int
    {
        return Media::query()
            ->where('original_name', 'like', $basename.'%')
            ->value('id');
    }

    /**
     * The header and footer navigations, as the live soa-htc.org carries them.
     * Only filled when the menu is new — an edited menu is the admin's, not the
     * seeder's.
     */
    private function seedMenus(): void
    {
        $cookiePolicy = Page::query()->where('slug', 'cookie-policy')->value('id');

        $menus = [
            'public-header' => ['Public header', [
                /*
                 * Every item is an anchor into the front page, as on the live
                 * site (owner, 2026-08-27). The entry screens exist, and for a
                 * while these two opened them directly — but a navigation whose
                 * items drop a visitor straight into a form skips the part of
                 * the page that says what the form is for. The forms are reached
                 * from the section, by somebody who has read it.
                 *
                 * "Sample Exam" and "Check Results" are the two columns of one
                 * band, and each carries its own anchor — sharing the band's
                 * `block_Results` lit both items at once.
                 */
                ['type' => 'custom', 'url' => '/#block_Start', 'label' => 'Start Quiz'],
                ['type' => 'custom', 'url' => '/#block_Sample', 'label' => 'Sample Exam'],
                ['type' => 'custom', 'url' => '/#block_CheckResults', 'label' => 'Check Results'],
                ['type' => 'custom', 'url' => '/#block_Coordinators', 'label' => 'Coordinators'],
                ['type' => 'custom', 'url' => '/#block_CompetitionRules', 'label' => 'Category check'],
            ]],
            'public-footer' => ['Public footer', [
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/pvc-policy/', 'label' => 'Privacy Policy', 'link_target' => '_blank'],
                // The live site links its own cookie policy by absolute URL; here
                // it is a page we hold, so it points at the page itself.
                ['type' => 'page', 'page_id' => $cookiePolicy, 'label' => 'Cookie Policy'],
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/data-processors/', 'label' => 'Data Processors', 'link_target' => '_blank'],
                ['type' => 'custom', 'url' => 'https://hippo-thecontest.org/dpa/', 'label' => 'DPA', 'link_target' => '_blank'],
            ]],
        ];

        foreach ($menus as $slug => [$name, $items]) {
            $menu = Menu::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);

            if ($menu->items()->exists()) {
                continue;
            }

            foreach ($items as $position => $item) {
                if (($item['type'] ?? null) === 'page' && ($item['page_id'] ?? null) === null) {
                    continue;
                }

                $menu->items()->create($item + ['position' => $position]);
            }
        }
    }
}
