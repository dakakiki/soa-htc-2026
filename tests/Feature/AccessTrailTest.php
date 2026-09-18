<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Signing in, signing out and being turned away are written to the trail.
 *
 * Asked for by the owner on 2026-09-17: accounts had been used for things nobody
 * could afterwards pin on anybody. Nothing recorded sign-ins before this — the
 * trail covered who was GRANTED authority, never who used it.
 */
class AccessTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Sanctum only starts a session for a first-party request; the browser sends this Origin itself. */
    private function spa(): TestResponse|static
    {
        return $this->withHeader('Origin', config('app.url'));
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    public function test_a_sign_in_is_written_down_with_who_and_from_where(): void
    {
        $admin = $this->admin();

        $this->spa()->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk();

        $row = AuditLog::where('action', 'auth.signed_in')->sole();

        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame($admin->name, $row->actor_label);
        $this->assertSame($admin::class, $row->subject_type);
        $this->assertNotNull($row->ip_address);
    }

    /**
     * 🔴 A browser coming back on its "remember me" cookie is not a sign-in, and
     * three of them arriving together are not three sign-ins.
     *
     * Reported from STAGE on 2026-09-18: one coordinator, three identical
     * `auth.signed_in` rows inside one second, same address and same browser.
     * Nobody signed in three times. The session had expired and the cookie had
     * not, and the SPA opens several API calls at once on boot — none of them
     * held a session, so each rebuilt its own out of the same cookie, and
     * Laravel fires `Login` for every one of those.
     *
     * 🪤 `$event->remember` cannot separate the two: it is true here AND for
     * somebody signing in with the box ticked. `viaRemember()` can, and
     * `userFromRecaller()` sets it before the event is fired.
     *
     * 🪤 Driven through the guard rather than over HTTP. The recall needs a
     * request carrying the recaller and no session, which the test client fights
     * — and a test that quietly ends up at a 401 proves only that a stranger is
     * turned away. This runs the real path: `viaRemember()` is asserted true, so
     * the recall demonstrably happened.
     */
    public function test_a_browser_returning_on_its_remember_cookie_is_not_a_sign_in(): void
    {
        $admin = $this->admin();
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        $name = Auth::guard('web')->getRecallerName();
        $recaller = implode('|', [
            $admin->getAuthIdentifier(),
            $admin->getRememberToken(),
            // Laravel's own third segment: the password hash, so that changing
            // the password invalidates every remembered browser.
            $admin->getAuthPassword(),
        ]);

        // Three calls arriving with the cookie and no session — the SPA's boot.
        foreach (range(1, 3) as $ignored) {
            Auth::forgetGuards();

            $request = Request::create('/api/auth/user');
            $request->cookies->set($name, $recaller);
            $request->setLaravelSession(app('session.store'));
            app('session.store')->flush();

            $guard = Auth::guard('web');
            $guard->setRequest($request);

            $this->assertSame($admin->id, $guard->user()?->id);
            $this->assertTrue($guard->viaRemember(), 'the recall did not happen, so nothing here is being tested');
        }

        $this->assertSame(
            0,
            AuditLog::where('action', 'auth.signed_in')->count(),
            'a remembered return was written down as a sign-in — and one return writes one row per '
            .'parallel call, which is how three appeared for one coordinator inside one second',
        );
    }

    /** And a real sign-in still writes exactly one, remember box ticked or not. */
    public function test_a_sign_in_with_remember_me_is_still_written_down_once(): void
    {
        $admin = $this->admin();

        $this->spa()->postJson('/api/auth/login', [
            'email' => $admin->email, 'password' => 'password', 'remember' => true,
        ])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'auth.signed_in')->count());
    }

    public function test_a_sign_out_is_written_down(): void
    {
        $this->actingAs($this->admin())->spa()->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame(1, AuditLog::where('action', 'auth.signed_out')->count());
    }

    /**
     * The failures are the half the owner actually asked about: an account being
     * guessed at looks like nothing until the attempts sit beside the sign-in
     * that eventually worked.
     */
    public function test_a_failed_sign_in_keeps_the_address_and_never_the_password(): void
    {
        $this->spa()->postJson('/api/auth/login', ['email' => 'admin@soahtc.test', 'password' => 'not-the-password'])
            ->assertStatus(422);

        $row = AuditLog::where('action', 'auth.failed')->sole();

        $this->assertSame('admin@soahtc.test', $row->after['email']);

        // 🔴 The Failed event hands the password over beside the address. It must
        // not reach a table that is kept for years and is not wiped at rollover.
        $this->assertStringNotContainsString('not-the-password', json_encode($row->getAttributes()));
        $this->assertArrayNotHasKey('password', $row->after);
    }

    public function test_an_address_that_belongs_to_nobody_is_still_written_down(): void
    {
        $this->spa()->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever'])
            ->assertStatus(422);

        $row = AuditLog::where('action', 'auth.failed')->sole();

        // Nobody to attribute it to, which is itself the finding.
        $this->assertNull($row->actor_id);
        $this->assertNull($row->subject_id);
        $this->assertSame('nobody@example.test', $row->after['email']);
    }

    /**
     * The competitors are deliberately left out: fifty thousand children
     * identifying would bury the handful of lines this is for, and
     * `student_sessions` already carries their side with its own ip and device.
     */
    public function test_a_competitor_identifying_does_not_reach_the_trail(): void
    {
        $before = AuditLog::count();

        $this->postJson('/api/identify', [
            'competitor_number' => '14000001',
            'date_of_birth' => '2010-01-01',
            'country_id' => 1,
        ]);

        $this->assertSame($before, AuditLog::count());
    }
}
