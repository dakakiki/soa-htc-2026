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
    }
}
