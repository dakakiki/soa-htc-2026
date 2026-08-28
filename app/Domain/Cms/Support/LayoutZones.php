<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\BlockType;

/**
 * The layout zones the application shell offers (ADR-0043, PROJECT_CONTEXT §8.6).
 *
 * This registry lives in code on purpose. In the legacy app a position was a row
 * an admin created by typing a name, so a position with no matching slot in the
 * template rendered nowhere and nothing reported it. Here a zone exists only if
 * the shell actually draws it, and the admin arranges what goes inside.
 *
 * Two kinds of zone live here, and the difference is deliberate:
 *
 *  - The front page is a LIST. Sections are added, reordered and switched off,
 *    and the order is the page.
 *  - Everything else holds ONE record, edited as a form: the chrome (header,
 *    footer), whose shape the design fixes and whose values change; and the copy
 *    of a screen the application draws itself (sign-in), which is a form with a
 *    heading and a paragraph above it.
 *
 * The owner's rule (2026-08-25): every screen that carries a heading and a
 * paragraph gets an admin for them. Screens join as zones rather than through a
 * second mechanism, so there stays one place text is edited and one place it is
 * validated. Field labels and buttons do NOT join — those are interface, and an
 * admin renaming "E-mail" breaks a form rather than improving a page.
 *
 * ADR-0043 left the chrome out of the builder entirely, on the reasoning that its
 * values already lived in the theme settings and the CMS menus. That was true of
 * the logo and the links and false of everything else: which menu the header drew,
 * the footer's paragraph and both of its column headings were literals in the code
 * and in `en.ts`, changeable only by a commit. ADR-0045 corrects it — not by giving
 * the chrome a builder, but by giving it the fields it always had, in one place.
 *
 * The status strip (`public.top`) is still absent, and for the original reason:
 * every value in it is derived from the data (which round, whether a competition
 * quiz is active). There is nothing there for an admin to set.
 */
final class LayoutZones
{
    /** The public front page: one zone, ordered sections. */
    public const PUBLIC_HOME = 'public.home';

    /** The public header: one settings record. */
    public const PUBLIC_HEADER = 'public.header';

    /** The public footer: one settings record. */
    public const PUBLIC_FOOTER = 'public.footer';

    /** The sign-in screen's copy: one record. */
    public const PUBLIC_LOGIN = 'public.login';

    /**
     * The competitor entry screen's copy — one zone per stream, keyed by the same
     * word the route carries (`/student/access/{mode}`). Two zones rather than one
     * record with two sets of fields: a visitor arrives at one of them from one of
     * two buttons, and an admin editing "the sample screen" should find its words
     * without picking them out of the competition screen's.
     */
    public const PUBLIC_IDENTIFY_COMPETITION = 'public.identify.competition';

    public const PUBLIC_IDENTIFY_SAMPLE = 'public.identify.sample';

    /**
     * The third stream through the same screen: looking up your own results
     * (owner, 2026-08-27). It asks for no exam password, because it opens no
     * exam — so it is a different offer to whoever arrives at it, and gets its
     * own words for the same reason the other two do.
     */
    public const PUBLIC_IDENTIFY_RESULTS = 'public.identify.results';

    /**
     * The coordinator registration screen's copy — one record for both steps
     * (ADR-0053). Unlike the two entry streams above, there is one of these: a
     * visitor does not arrive at "the sent screen", they are carried to it by
     * having sent the form, so its words belong with the words that led there.
     */
    public const PUBLIC_REGISTER = 'public.register';

    /**
     * The two halves of password recovery (ADR-0063). Two zones for the same
     * reason the entry streams are two: a person meets one of these screens or
     * the other, and the second one they reach from an e-mail without having
     * seen the first. An administrator editing "the screen that asks for the
     * address" should not have to pick its words out of the screen that sets the
     * password.
     */
    public const PUBLIC_FORGOT_PASSWORD = 'public.forgot-password';

    public const PUBLIC_RESET_PASSWORD = 'public.reset-password';

    /**
     * Zone key => what it is, and which block types it accepts.
     *
     * @return array<string, array{label: string, description: string, types: list<BlockType>}>
     */
    public static function all(): array
    {
        return [
            self::PUBLIC_HOME => [
                'label' => 'Home',
                'description' => 'The sections of the public home page, in the order a visitor meets them.',
                'types' => [
                    BlockType::Hero,
                    BlockType::Notice,
                    BlockType::Category,
                    BlockType::SplitCta,
                    BlockType::Coordinators,
                    BlockType::Contact,
                    BlockType::News,
                    BlockType::ImageBand,
                ],
            ],
            self::PUBLIC_HEADER => [
                'label' => 'Header',
                'description' => 'The navigation shown at the top of every public page. The logo comes from Theme settings.',
                'types' => [BlockType::Header],
            ],
            self::PUBLIC_FOOTER => [
                'label' => 'Footer',
                'description' => 'The text and the link columns at the foot of every public page.',
                'types' => [BlockType::Footer],
            ],
            self::PUBLIC_LOGIN => [
                'label' => 'Sign in',
                'description' => 'The heading, the paragraph and the note under the form on the staff sign-in screen. The fields and the button are interface, not content.',
                'types' => [BlockType::Login],
            ],
            self::PUBLIC_REGISTER => [
                'label' => 'Register',
                'description' => 'The words on the coordinator registration screen, and on the panel shown once it has been sent for approval.',
                'types' => [BlockType::Register],
            ],
            self::PUBLIC_FORGOT_PASSWORD => [
                'label' => 'Forgot password',
                'description' => 'The words on the screen that asks for an address, and on the panel shown once a link has been asked for.',
                'types' => [BlockType::PasswordRecovery],
            ],
            self::PUBLIC_RESET_PASSWORD => [
                'label' => 'Set a new password',
                'description' => 'The words on the screen a recovery link leads to, and on the panel shown once the password has been changed.',
                'types' => [BlockType::PasswordRecovery],
            ],
            self::PUBLIC_IDENTIFY_COMPETITION => [
                'label' => 'Start quiz',
                'description' => 'The words on the competitor entry screen for the contest itself, where the exam password is asked for.',
                'types' => [BlockType::Identify],
            ],
            self::PUBLIC_IDENTIFY_SAMPLE => [
                'label' => 'Sample exam',
                'description' => 'The words on the same screen entered in practice mode, where no password is asked for.',
                'types' => [BlockType::Identify],
            ],
            self::PUBLIC_IDENTIFY_RESULTS => [
                'label' => 'Check results',
                'description' => 'The words on the same screen entered to look up results. No password is asked for — nothing is opened, only read.',
                'types' => [BlockType::Identify],
            ],
        ];
    }

    /**
     * Whether the zone holds a single record rather than a list of sections. The
     * editor branches on this, and so does the seeder.
     */
    public static function isSingle(string $zone): bool
    {
        $types = self::types($zone);

        return count($types) === 1 && $types[0]->isSingle();
    }

    public static function exists(string $zone): bool
    {
        return array_key_exists($zone, self::all());
    }

    /**
     * The block types this zone accepts, or an empty list for an unknown zone.
     *
     * @return list<BlockType>
     */
    public static function types(string $zone): array
    {
        return self::all()[$zone]['types'] ?? [];
    }

    public static function accepts(string $zone, BlockType $type): bool
    {
        return in_array($type, self::types($zone), true);
    }
}
