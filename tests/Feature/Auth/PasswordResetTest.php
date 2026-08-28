<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Recovering a forgotten password (ADR-0063).
 *
 * The rules the tests are here to hold, in the order they can be broken:
 *
 *  - the public form answers the same to an address with an account and to one
 *    without, so it cannot be used to ask who has one;
 *  - a link works once, only for the account it was issued to, and only for an
 *    hour;
 *  - the reset closes every session the account had open, because the reason for
 *    resetting may be that somebody else is sitting in one;
 *  - an administrator may send a link to an account they may edit, and to no
 *    other.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** An account with a password nobody in this file knows by accident. */
    private function coordinator(string $email = 'forgetful@soahtc.test'): User
    {
        $school = School::firstOrFail();

        $user = User::create([
            'name' => 'Forgetful Coordinator',
            'email' => $email,
            'password' => 'the-old-password',
            'country_id' => $school->country_id,
            'status' => 'active',
        ]);

        $assignment = $user->seasonAssignments()->create([
            'season_id' => SeasonContext::active()->id,
            'role_id' => Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail()->id,
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        return $user->refresh();
    }

    private function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    // ---------------------------------------------------------------- asking

    public function test_asking_for_a_link_sends_one_to_the_account(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        Mail::assertSent(PasswordResetLink::class, fn (PasswordResetLink $mail): bool => $mail->hasTo($user->email));
    }

    /**
     * The whole point of the endpoint's silence: byte for byte the same answer,
     * whether or not there is anybody behind the address.
     */
    public function test_an_address_with_no_account_is_answered_exactly_like_one_that_has(): void
    {
        $user = $this->coordinator();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@soahtc.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        // And nothing was sent to the address that has no account.
        Mail::assertSent(PasswordResetLink::class, 1);
    }

    public function test_the_mail_carries_a_working_link_and_says_which_account_it_is_for(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use ($user): bool {
            $rendered = $mail->render();

            return str_contains($rendered, rtrim((string) config('app.url'), '/').'/reset-password/'.$mail->token)
                && str_contains($rendered, $user->email);
        });
    }

    // --------------------------------------------------------------- setting

    public function test_the_link_sets_the_password_and_the_new_one_signs_in(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk()->assertJsonPath('data.reset', true);

        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));

        $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'a-brand-new-password',
            ])->assertOk();
    }

    /**
     * 🪤 Not "the old password stops working" as an afterthought — it is the one
     * thing a reset has to do, and a broker mis-wired to write a plain string
     * into a hashed column would pass every other test in this file.
     */
    public function test_the_old_password_stops_working(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'the-old-password',
        ])->assertStatus(422);
    }

    public function test_a_link_cannot_be_spent_twice(): void
    {
        $user = $this->coordinator();
        $token = $this->tokenFor($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/auth/reset-password', [
            ...$payload,
            'password' => 'a-second-password',
            'password_confirmation' => 'a-second-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // The second attempt changed nothing.
        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));
    }

    public function test_a_link_issued_for_one_account_does_not_open_another(): void
    {
        $mine = $this->coordinator('mine@soahtc.test');
        $theirs = $this->coordinator('theirs@soahtc.test');

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($mine),
            'email' => $theirs->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('the-old-password', $theirs->refresh()->password));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = $this->coordinator();
        $token = $this->tokenFor($user);

        // An hour and a minute later — `auth.passwords.users.expire` is 60.
        $this->travel(61)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('the-old-password', $user->refresh()->password));
    }

    /** The same floor as the registration form and the Users screen. */
    public function test_a_password_under_eight_characters_is_refused(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_repeat_that_does_not_match_is_refused(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * Somebody resetting a password may be doing it because somebody else knows
     * the old one. A new password that left the intruder's session open would
     * change nothing for as long as they kept clicking.
     */
    public function test_the_reset_closes_every_session_the_account_had_open(): void
    {
        $user = $this->coordinator();
        $before = $user->remember_token;

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'a-session-somebody-is-sitting-in',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
        // Another account's session, to prove the sweep is not indiscriminate.
        DB::table('sessions')->insert([
            'id' => 'somebody-elses-session',
            'user_id' => $this->admin()->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'a-session-somebody-is-sitting-in']);
        $this->assertDatabaseHas('sessions', ['id' => 'somebody-elses-session']);
        // "Keep me signed in" is a cookie the old token would still validate.
        $this->assertNotSame($before, $user->refresh()->remember_token);
    }

    // ----------------------------------------------------------- the admin's

    public function test_an_administrator_sends_a_link_instead_of_typing_a_password(): void
    {
        $user = $this->coordinator();

        $this->actingAs($this->admin())
            ->postJson('/api/users/'.$user->id.'/password-reset-link')
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        Mail::assertSent(PasswordResetLink::class, fn (PasswordResetLink $mail): bool => $mail->hasTo($user->email));
        // The account is untouched until somebody follows the link.
        $this->assertTrue(Hash::check('the-old-password', $user->refresh()->password));
    }

    public function test_the_link_an_administrator_sent_works_like_any_other(): void
    {
        $user = $this->coordinator();

        $this->actingAs($this->admin())
            ->postJson('/api/users/'.$user->id.'/password-reset-link')
            ->assertOk();

        $token = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));
    }

    /**
     * Told, rather than left waiting. The broker declines a second link within
     * the minute and sends nothing; an administrator answered "sent" would go on
     * expecting a mail that was never posted.
     */
    public function test_a_second_link_within_the_minute_says_so_rather_than_lying(): void
    {
        $user = $this->coordinator();

        $this->actingAs($this->admin())->postJson('/api/users/'.$user->id.'/password-reset-link')->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/users/'.$user->id.'/password-reset-link')
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        Mail::assertSent(PasswordResetLink::class, 1);
    }

    public function test_sending_a_link_needs_a_signed_in_account_that_may_edit_the_target(): void
    {
        $user = $this->coordinator();

        $this->postJson('/api/users/'.$user->id.'/password-reset-link')->assertUnauthorized();

        // A school coordinator holds no permission over anybody.
        $this->actingAs($user)
            ->postJson('/api/users/'.$this->admin()->id.'/password-reset-link')
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
