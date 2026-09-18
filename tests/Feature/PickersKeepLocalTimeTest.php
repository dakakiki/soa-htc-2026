<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A `<input type="datetime-local">` holds a bare wall clock — `2026-09-20T08:00`
 * with no zone on it — and the application stores time in UTC. Something has to
 * translate, and until 2026-09-18 nothing did: the picker's string went to the
 * server as if it were already UTC, so an administrator in Belgrade choosing
 * 08:00 scheduled 08:00 UTC, which is **ten o'clock to them**.
 *
 * 🔴 Storing UTC is right and is not what changed. Coordinators read the same
 * rows from Belgrade, Riyadh and Ulaanbaatar, and the only hour they can agree
 * on is the one nobody is standing in. What was wrong was the boundary, so the
 * boundary is where it is fixed — {@see resources/js/utils/localDateTime.ts}.
 *
 * The front has no test runner and is not getting one (ADR-0074, withdrawn), so
 * this reads the source, as `ManifestTest` does for the start address and
 * `PublicRoutesTest` for the reserved paths. It cannot prove the arithmetic; it
 * proves that no picker was left doing its own.
 */
class PickersKeepLocalTimeTest extends TestCase
{
    /** Every screen with a date-and-time picker translates at both ends. */
    public function test_every_datetime_picker_goes_through_the_one_translation(): void
    {
        $screens = $this->screensWithAPicker();

        $this->assertNotEmpty($screens, 'No picker found at all — has the search moved?');

        foreach ($screens as $path => $source) {
            // 🪤 A boolean, not assertStringContainsString: that one prints the
            // WHOLE file when it fails, and these files are a thousand lines.
            $this->assertTrue(
                str_contains($source, "from '@/utils/localDateTime'"),
                $path.' has a datetime picker and does not translate it. Its hour will be '
                .'whatever the difference is between the reader and UTC.',
            );
        }
    }

    /**
     * 🪤 The exact shape the bug had. `value.slice(0, 16)` takes the UTC wall
     * clock off an ISO string and drops it into a picker that means local time,
     * so reopening a scheduled message showed an hour nobody had chosen. It
     * reads as harmless tidying, which is why it is named here.
     */
    public function test_no_picker_is_filled_by_cutting_an_iso_string_up(): void
    {
        foreach ($this->screensWithAPicker() as $path => $source) {
            $this->assertSame(
                0,
                preg_match('/\.slice\(0,\s*16\)/', $source),
                $path.' fills a picker by cutting an ISO string, which hands it UTC.',
            );
        }
    }

    /**
     * 🪤 And the same trap one level down: `toISOString()` prints UTC, which is
     * exactly what must not reach the picker. The offset has to come off first.
     */
    public function test_the_translation_shifts_by_the_offset_before_printing(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/utils/localDateTime.ts'));

        $this->assertStringContainsString('getTimezoneOffset()', $source);
    }

    /** @return array<string, string> path => source */
    private function screensWithAPicker(): array
    {
        $found = [];
        $root = base_path('resources/js');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'type="datetime-local"')) {
                $found[str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname())] = $source;
            }
        }

        return $found;
    }
}
