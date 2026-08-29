<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The importers raise the execution limit for the long request they run
        // in, but PHPUnit runs the whole suite in one process, so that limit
        // outlives the test that triggered it and later kills an unrelated one.
        // Give every test the CLI default back: no limit.
        @set_time_limit(0);

        /*
         * The suite does not test the bundle (ADR-0069).
         *
         * `app.blade.php` calls `@vite(...)` without a guard, which is right for
         * production — a page served without its assets is a broken deploy and
         * should say so rather than render blank. But `public/build` is not in the
         * repository, so every server-rendered test passed or failed depending on
         * whether somebody had happened to run `npm run build` on that machine.
         * On a clean checkout — CI, or a colleague's first day — `CmsApiTest` died
         * with a 500 and a ViteManifestNotFoundException while asserting a meta tag.
         *
         * What actually guards the bundle is the `static` CI job, which builds it.
         */
        $this->withoutVite();
    }
}
