<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Reading a notice and putting one away are two acts, on three screens.
 *
 * The front has no test runner and is not getting one (ADR-0074, withdrawn), so
 * this reads the sources, as `MotionDoesNotFlashTest` reads the stylesheet.
 * Nothing here can watch a tap; what it can do is keep the next person from
 * tidying the two acts back into one.
 */
class ReadingIsNotDismissingTest extends TestCase
{
    /**
     * Where a coordinator can meet a notice: the two inboxes.
     *
     * 🔴 Welcome was the third until 2026-09-18, when the owner took the notices
     * off it — *„sa welcome screen koordinatora sada treba skloniti notifikacije.
     * vise nema potrebe jer ima zvonce sa badge"*. The bell now says there is
     * something and the inbox is one tap away, so the cards were a second copy
     * of a screen that already exists.
     */
    private const SCREENS = [
        'resources/js/pages/app/MyMessagesPage.vue',
        'resources/js/pages/messages/InboxPage.vue',
    ];

    /**
     * 🔴 The decrement on dismiss is guarded by whether the row was unread.
     *
     * A notice that was read left the count when it was read. Taking another off
     * for putting the same row away runs the bell LOW — and low is the invisible
     * failure: the count is clamped at zero, so it does not go negative and look
     * broken, it says "nothing unread" over an inbox that has some.
     */
    public function test_putting_away_a_read_notice_does_not_take_it_off_the_bell_twice(): void
    {
        foreach (self::SCREENS as $path) {
            $source = $this->source($path);

            $this->assertMatchesRegularExpression(
                '/if \(!(row|notice)\.read\) \{\s*notices\.oneLess\(\);\s*\}/',
                $source,
                $path.' takes one off the bell for putting a notice away without asking whether it was '
                .'still counted. Read it once, put it away, and the bell is short by one.',
            );
        }
    }

    /**
     * 🪤 The tap target and the × are SIBLINGS. A button inside a button is
     * markup a browser is entitled to take apart, and the one that disappears is
     * the inner one — the ×, which is the only way a notice ever leaves a screen.
     */
    public function test_the_dismiss_button_is_not_inside_the_tap_target(): void
    {
        foreach (self::SCREENS as $path) {
            $source = $this->source($path);

            $tap = strpos($source, '@click="read(');
            $this->assertNotFalse($tap, $path.' has no tap target; has reading moved elsewhere?');

            $closes = strpos($source, '</button>', $tap);
            $this->assertNotFalse($closes);

            $away = strpos($source, '@click="putAway(') ?: strpos($source, '@click="dismiss(');
            $this->assertNotFalse($away, $path.' has no way to put a notice away.');

            $this->assertGreaterThan(
                $closes,
                $away,
                $path.' nests the × inside the row that marks it read.',
            );
        }
    }

    /**
     * 🔴 The bell is drawn from the server's unread count, in both shells —
     * never from the length of a list. The list is capped at ten, and a bell
     * that stops counting at ten is a bell that lies quietly.
     */
    public function test_both_bells_count_what_has_not_been_read(): void
    {
        foreach ([
            'resources/js/components/app/AppCoordinatorScreen.vue',
            'resources/js/layouts/AdminLayout.vue',
        ] as $path) {
            $this->assertStringContainsString('notices.unread', $this->source($path), $path);
        }

        $this->assertStringContainsString('data.meta.unread', $this->source('resources/js/stores/notices.ts'));
    }

    /**
     * 🔴 And Welcome does not grow them back by accident. It carried the same
     * cards, with the same two acts on them, and the owner took them off: a
     * screen whose whole job is "which of the three things are you asking
     * about" had a message thread in the middle of it.
     *
     * 🪤 This also removed one of the API calls the application makes on boot —
     * which is the same burst that wrote three sign-in rows for one arrival
     * (ADR-0128). Putting the cards back puts that call back.
     */
    public function test_welcome_asks_its_question_and_carries_no_notices(): void
    {
        $welcome = $this->source('resources/js/pages/app/CoordinatorHomePage.vue');

        $this->assertStringNotContainsString('messageInbox', $welcome);
        $this->assertStringNotContainsString('useNoticesStore', $welcome);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
