<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/**
 * The section types a layout zone may be built from (ADR-0043).
 *
 * These are not generic containers: each one mirrors a section of the approved
 * public design and carries only the fields that design tolerates. That is the
 * deliberate limit on the builder — the admin arranges the page, but cannot
 * invent a section the design has no answer for.
 */
enum BlockType: string
{
    case Hero = 'hero';
    case Notice = 'notice';
    case Category = 'category';
    case SplitCta = 'split_cta';
    case Coordinators = 'coordinators';
    case Contact = 'contact';
    case News = 'news';
    case ImageBand = 'image_band';
    // Chrome. Not sections of a page but the shell around every one of them, so
    // each is a singleton in its own zone and carries settings rather than copy.
    case Header = 'header';
    case Footer = 'footer';
    // Screen copy. A screen the application draws itself — a form, not something
    // assembled from sections — but whose heading and paragraph are still words
    // somebody has to be able to change without a commit. `Identify` serves two
    // zones, one per entry stream: the same screen, two sets of words.
    case Login = 'login';
    case Identify = 'identify';
    // The coordinator registration screen (ADR-0053). One record covers both of
    // its steps — the form and the "sent for approval" panel that replaces it —
    // because they are one screen with one thing to say, told in two halves. An
    // admin editing "the registration screen" should not have to find its second
    // half in another zone.
    case Register = 'register';
    // Password recovery (ADR-0063). One type over two zones, like `Identify`:
    // asking for the link and choosing the new password are two screens a person
    // arrives at separately — the second one straight from an e-mail, having
    // never seen the first — so each gets its own words rather than sharing a
    // record whose halves would be edited in the wrong place.
    case PasswordRecovery = 'password_recovery';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Notice => 'Notice',
            self::Category => 'Categories',
            self::SplitCta => 'Two calls to action',
            self::Coordinators => 'Coordinator access',
            self::Contact => 'Contact',
            self::News => 'Latest news',
            self::ImageBand => 'Image band',
            self::Header => 'Header',
            self::Footer => 'Footer',
            self::Login => 'Sign in',
            self::Identify => 'Competitor entry',
            self::Register => 'Coordinator registration',
            self::PasswordRecovery => 'Password recovery',
        };
    }

    /**
     * Whether the type is the single record of its zone rather than one section
     * among several. The editor shows these as a form, not as a list with an "Add
     * section" button — there is nothing to add or reorder.
     *
     * Covers two kinds: the chrome (header, footer) and the copy of a screen the
     * application draws itself (sign-in, competitor entry). Both are one record
     * edited as fields; only the front page is a list.
     */
    public function isSingle(): bool
    {
        return in_array($this, [
            self::Header, self::Footer, self::Login, self::Identify, self::Register, self::PasswordRecovery,
        ], true);
    }
}
