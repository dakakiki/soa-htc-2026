<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What the application is allowed to animate.
 *
 * 🔴 Two rules, both the owner's, both from 2026-09-18:
 *
 *  1. **No change of brightness.** A screen that fades in is a screen whose
 *     brightness rises out of the page behind it, and on the swap between a
 *     navy application screen and a light website one that reads as a blink.
 *     The guideline it touches (WCAG 2.3.1) counts flashes rather than fades,
 *     so the answer is not to argue that one fade sits under a threshold — it
 *     is to have no luminance change at all. Movement only.
 *  2. **Nothing moves for somebody who asked their system not to move things.**
 *
 * The front has no test runner and is not getting one (ADR-0074, withdrawn), so
 * this reads the stylesheet, as `ManifestTest` reads the router and
 * `PickersKeepLocalTimeTest` the forms. It cannot watch an animation; it can
 * keep the next person from adding a fade back because it looked nicer.
 */
class MotionDoesNotFlashTest extends TestCase
{
    /** The keyframes the screens animate with carry no opacity at all. */
    public function test_no_animation_changes_brightness(): void
    {
        foreach ($this->keyframes() as $name => $body) {
            $this->assertSame(
                0,
                preg_match('/\bopacity\b/', $body),
                "@keyframes {$name} changes opacity. A screen arriving must move, not brighten: "
                .'between a navy screen and a light one, a fade reads as a blink.',
            );
        }
    }

    /** And each of them only runs for somebody who has not asked for stillness. */
    public function test_every_animation_is_behind_the_reduced_motion_question(): void
    {
        $css = $this->stylesheet();

        foreach (['.page-enter-active', '.rise'] as $selector) {
            $at = strpos($css, $selector.' {');

            $this->assertNotFalse($at, $selector.' has gone; has the motion moved elsewhere?');

            $guard = strrpos(substr($css, 0, $at), '@media (prefers-reduced-motion: no-preference)');

            $this->assertNotFalse(
                $guard,
                $selector.' is not inside a reduced-motion query, so it moves for everybody.',
            );
        }
    }

    /**
     * 🪤 The rule is about what SCREENS do, not about a spinner or a pulsing
     * dot, which are states rather than arrivals. Those are Tailwind's own
     * utilities and are not defined here — so this reads only the keyframes
     * this stylesheet declares.
     *
     * @return array<string, string>
     */
    private function keyframes(): array
    {
        preg_match_all(
            '/@keyframes\s+([A-Za-z0-9_-]+)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/',
            $this->stylesheet(),
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'No keyframes found at all — has the stylesheet moved?');

        $found = [];

        foreach ($matches as $match) {
            $found[$match[1]] = $match[2];
        }

        return $found;
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }
}
