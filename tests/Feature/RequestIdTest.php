<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Every line a request writes carries the request's name (ADR-0070).
 *
 * The question this exists to answer is the operator's: "a coordinator says it
 * broke at ten past nine — which of these lines is theirs?" So the tests are
 * about what ends up in the log CONTEXT and what comes back in the header, not
 * about the format of the file.
 *
 * 🪤 `Log::listen` sees the context AFTER the logger has merged its own
 * (`Logger::writeLog` merges `$this->context` before firing `MessageLogged`),
 * which is what makes `withContext` observable from a test at all.
 */
class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    /*
     * 🪤 Without this the console half of the test proves nothing.
     * `CommandStarting` is dispatched by a Symfony listener that
     * `Kernel::handle()` wires up when artisan runs for real —
     * `$this->artisan()` goes through `Kernel::call()` and never wires it. The
     * framework ships this trait for exactly that.
     */
    use WithConsoleEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /**
     * The context of every line written while handling one request.
     *
     * @return list<array<string, mixed>>
     */
    private function captureLogContext(callable $act): array
    {
        $records = [];
        Log::listen(function (MessageLogged $event) use (&$records): void {
            $records[] = $event->context;
        });

        $act();

        return $records;
    }

    public function test_a_response_carries_the_name_of_the_request_that_made_it(): void
    {
        $response = $this->getJson('/api/theme')->assertOk();

        $id = $response->headers->get(AssignRequestId::HEADER);

        $this->assertNotNull($id, 'The response did not say which request it was.');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $id);
    }

    public function test_two_requests_get_two_different_names(): void
    {
        $first = $this->getJson('/api/theme')->headers->get(AssignRequestId::HEADER);
        $second = $this->getJson('/api/theme')->headers->get(AssignRequestId::HEADER);

        $this->assertNotSame($first, $second);
    }

    /**
     * A proxy in front of the application stamps its own id, and honouring it is
     * what lets its logs and these line up.
     */
    public function test_an_id_the_caller_already_had_is_kept(): void
    {
        $response = $this->withHeader(AssignRequestId::HEADER, 'edge-7f3a9c11')
            ->getJson('/api/theme')
            ->assertOk();

        $this->assertSame('edge-7f3a9c11', $response->headers->get(AssignRequestId::HEADER));
    }

    /**
     * 🪤 The header is a stranger's text going into a log file. A newline in it
     * would let anyone forge log lines — write `abc\n[2026-08-29 09:00:00]
     * production.ERROR: nothing to see here` and the file says what the caller
     * wanted it to say.
     */
    public function test_an_id_that_could_forge_a_log_line_is_thrown_away(): void
    {
        foreach ([
            "abc\n[2026-08-29 09:00:00] production.ERROR: forged",
            'has spaces',
            str_repeat('x', 65),
            '',
        ] as $hostile) {
            $id = $this->withHeader(AssignRequestId::HEADER, $hostile)
                ->getJson('/api/theme')
                ->headers->get(AssignRequestId::HEADER);

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]{12}$/',
                (string) $id,
                'A hostile X-Request-Id was echoed back instead of being replaced.',
            );
        }
    }

    // ------------------------------------------------------------------ the log

    public function test_the_log_says_which_request_wrote_the_line(): void
    {
        $records = $this->captureLogContext(function (): void {
            $this->withHeader(AssignRequestId::HEADER, 'known-id-1')
                ->getJson('/api/theme');
            Log::info('something happened');
        });

        $this->assertNotEmpty($records);
        $context = end($records);

        $this->assertSame('known-id-1', $context['request_id']);
        $this->assertSame('GET', $context['method']);
        $this->assertSame('/api/theme', $context['path']);
    }

    /**
     * Who was acting, added the moment a guard resolves it — not by the
     * middleware, which runs before authentication has happened.
     */
    public function test_the_log_says_who_was_acting_once_they_are_known(): void
    {
        $admin = $this->admin();

        $records = $this->captureLogContext(function () use ($admin): void {
            $this->actingAs($admin)->getJson('/api/auth/user')->assertOk();
            Log::info('something happened');
        });

        $context = end($records);

        $this->assertSame($admin->id, $context['user_id']);
        $this->assertArrayHasKey('guard', $context);
    }

    /**
     * A line written before anybody signed in is anonymous, rather than wrongly
     * attributed to whoever signs in later.
     */
    public function test_a_line_written_by_a_stranger_names_nobody(): void
    {
        $records = $this->captureLogContext(function (): void {
            $this->getJson('/api/theme');
            Log::info('something happened');
        });

        $context = end($records);

        $this->assertArrayHasKey('request_id', $context);
        $this->assertArrayNotHasKey('user_id', $context);
    }

    /**
     * The console gets a name too. This application does a great deal from the
     * command line — the imports, `season:reset`, the rollover — and those are
     * exactly the runs somebody reads the log about afterwards.
     */
    public function test_a_command_says_which_command_it_was(): void
    {
        $records = $this->captureLogContext(function (): void {
            $this->artisan('season:rehearse --force')->assertSuccessful();
            Log::info('something happened');
        });

        $context = end($records);

        $this->assertSame('season:rehearse', $context['command']);
        // One field to grep for, whether the run came through a browser or a terminal.
        $this->assertStringStartsWith('cli-', (string) $context['request_id']);
    }
}
