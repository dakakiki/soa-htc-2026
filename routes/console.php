<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Close exams nobody came back to.
 *
 * A competitor whose browser or connection dies mid-exam leaves the attempt open.
 * The application finishes it the moment they return — `AttemptController` calls
 * `finalizeIfExpired` on start, on show and on submit — but that is the only path
 * there was, so an attempt belonging to somebody who never returns stayed
 * `in_progress` for good: never graded, never published, and counted forever as
 * started but not submitted.
 *
 * It is not a rare case. In the legacy data 1,743 of 80,287 started tests carry no
 * mark at all — a little over two in a hundred.
 *
 * The sweep completes them at the moment their own clock ran out, not at the
 * moment it happened to run, and grades whatever reached the database.
 *
 * 🪤 This file was empty until 2026-08-31, so nothing in the application was
 * scheduled at all and `attempts:finalize-expired` — which says in its own
 * docblock that it is safe to run on a schedule — was never once run by anything.
 * A server needs one cron line for the scheduler itself:
 *
 *     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('attempts:finalize-expired')
    ->everyFiveMinutes()
    // The sweep is quick, but a slow one must not have a second copy started on
    // top of it: two graders on one attempt is not a race worth running.
    ->withoutOverlapping();
