<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Staging carries the real registrations and must stay out of the search index;
 * production is a public site and must stay in it. Both answers come out of the
 * same two files, so the two are easy to mix up in a hurry — which is the whole
 * reason this test exists.
 *
 * 🔴 The dangerous edit is not the one that forgets staging. It is the one that
 * remembers it by putting `Disallow: /` into `public/robots.txt`, which is
 * SHARED: staging would go quiet and so would the public site, and nobody would
 * notice until the pitch.
 *
 * None of this can be proved by a request in the test suite — `.htaccess` is
 * read by Apache and PHP never sees it. What is checked here is that the two
 * halves still name each other, because either half alone does nothing.
 */
class StagingIsNotIndexedTest extends TestCase
{
    public function test_the_shared_robots_file_still_lets_production_be_indexed(): void
    {
        $robots = $this->file('public/robots.txt');

        $this->assertMatchesRegularExpression('/^\s*Disallow:\s*$/mi', $robots,
            'public/robots.txt is the PUBLIC site\'s file. An empty Disallow is what lets it be indexed.');
    }

    public function test_staging_has_its_own_robots_file_that_refuses_everything(): void
    {
        $robots = $this->file('public/robots-staging.txt');

        $this->assertMatchesRegularExpression('/^\s*User-agent:\s*\*\s*$/mi', $robots);
        $this->assertMatchesRegularExpression('/^\s*Disallow:\s*\/\s*$/mi', $robots,
            'Staging asks every crawler to stay out of everything.');
    }

    /**
     * The file is served instead of the shared one, and only on the machine
     * that carries the staging marker — production reads none of it.
     */
    public function test_the_server_config_swaps_in_the_staging_robots_file(): void
    {
        $htaccess = $this->file('public/.htaccess');

        $this->assertStringContainsString('<IfFile "/usr/www/users/dvpjdhdo/.stage">', $htaccess,
            'The switch is a file only staging has, as with the password (ADR-0080).');
        $this->assertStringContainsString('RewriteRule ^robots\.txt$ robots-staging.txt', $htaccess);
    }

    /**
     * 🔴 robots.txt only asks. The header is what keeps a page that was fetched
     * anyway — or linked from somewhere — out of the index.
     */
    public function test_the_server_config_sends_noindex_on_every_staging_response(): void
    {
        $htaccess = $this->file('public/.htaccess');

        $this->assertMatchesRegularExpression(
            '/Header always set X-Robots-Tag "noindex[^"]*"/',
            $htaccess,
            'Without this a crawler that ignores robots.txt indexes the lot.',
        );
    }

    private function file(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }
}
