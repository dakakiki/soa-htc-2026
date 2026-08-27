<?php

namespace Tests\Feature;

use App\Domain\Assessment\Enums\QuizType;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Cms\Support\PublicRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The register of screens an admin may point a button at.
 *
 * Its whole value is that a `route` target cannot name somewhere that is not
 * there — so the register itself has to be true. Nothing at runtime can check
 * it: the SPA's routes are a module-local const in a TypeScript file, and the
 * server never sees them. This test reads that file.
 */
class PublicRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTER = 'resources/js/router/index.ts';

    /**
     * The SPA's declared paths as regular expressions.
     *
     * `:mode(sample|competition|results)` keeps its alternatives, a plain
     * `:param` becomes one segment. The two catch-alls are left out on purpose:
     * `/:slug` serves CMS pages and `/:pathMatch(.*)*` is Not Found, and either
     * would match everything and turn this test into a formality.
     *
     * @return list<string>
     */
    private function routerPatterns(): array
    {
        $source = file_get_contents(base_path(self::ROUTER));

        $this->assertNotFalse($source, self::ROUTER.' could not be read.');
        preg_match_all("~path:\s*'([^']+)'~", $source, $matches);
        $this->assertNotEmpty($matches[1], 'No routes found in '.self::ROUTER.'; the pattern has gone stale.');

        $patterns = [];

        foreach ($matches[1] as $path) {
            if (str_contains($path, 'pathMatch') || $path === '/:slug') {
                continue;
            }

            $pattern = preg_replace('~:\w+\(([^)]*)\)~', '($1)', $path);
            $pattern = preg_replace('~:\w+~', '[^/]+', (string) $pattern);
            $patterns[] = '~^'.$pattern.'$~';
        }

        return $patterns;
    }

    public function test_every_registered_screen_is_a_screen_the_spa_serves(): void
    {
        $patterns = $this->routerPatterns();

        foreach (PublicRoutes::paths() as $path) {
            $matched = false;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    $matched = true;
                    break;
                }
            }

            $this->assertTrue(
                $matched,
                $path.' is offered to admins but no route in '.self::ROUTER.' answers it.',
            );
        }
    }

    public function test_the_register_is_not_empty_and_carries_a_label_for_each_screen(): void
    {
        $options = PublicRoutes::options();

        $this->assertNotEmpty($options);
        $this->assertSame(PublicRoutes::paths(), array_column($options, 'value'));

        foreach ($options as $option) {
            $this->assertNotSame('', trim($option['label']));
        }
    }

    /**
     * An address is compared by the screen it names, not by how it was typed.
     * A note written in the editor may well carry a query string or a trailing
     * slash, and it still points at the same shut door.
     */
    public function test_an_address_is_recognised_however_it_was_written(): void
    {
        $this->assertTrue(PublicRoutes::has('/student/access/sample'));
        $this->assertTrue(PublicRoutes::has('/student/access/sample/'));
        $this->assertTrue(PublicRoutes::has('/student/access/sample?from=hero'));
        $this->assertTrue(PublicRoutes::has('/student/access/sample#top'));
        $this->assertTrue(PublicRoutes::has('/'));

        $this->assertFalse(PublicRoutes::has('/student/acess/sample'));
        $this->assertFalse(PublicRoutes::has('https://example.test/student/access/sample'));
    }

    /** An empty target is not the front page — see PublicRoutes::has(). */
    public function test_nothing_is_not_a_screen(): void
    {
        $this->assertFalse(PublicRoutes::has(''));
        $this->assertFalse(PublicRoutes::has('   '));
    }

    public function test_a_screen_carries_the_season_that_governs_it(): void
    {
        $this->assertSame('competition', PublicRoutes::gate('/student/access/competition'));
        $this->assertSame('sample', PublicRoutes::gate('/student/access/sample'));
        // Looking up marks is open to every candidate all year.
        $this->assertNull(PublicRoutes::gate('/student/access/results'));
        $this->assertNull(PublicRoutes::gate('/login'));
    }

    public function test_the_shut_screens_follow_the_active_quizzes(): void
    {
        $this->seed();

        // Out of season both entries are shut, and nothing else ever is.
        $this->assertSame(
            ['/student/access/competition', '/student/access/sample'],
            PublicRoutes::shutPaths(),
        );

        Quiz::create(['title' => 'Practice', 'quiz_type' => QuizType::Sample, 'status' => 'active']);
        $this->assertSame(['/student/access/competition'], PublicRoutes::shutPaths());

        Quiz::create(['title' => 'Round 14', 'quiz_type' => QuizType::Competition, 'status' => 'active']);
        $this->assertSame([], PublicRoutes::shutPaths());
    }
}
