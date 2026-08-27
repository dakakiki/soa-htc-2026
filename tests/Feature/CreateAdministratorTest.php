<?php

namespace Tests\Feature;

use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening the first administrator on a fresh installation.
 *
 * Only `RolePermissionSeeder` is run here, deliberately: that is exactly what a
 * migrated production database holds, with no users and — the part that made
 * this necessary — no season.
 */
class CreateAdministratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_fresh_install_starts_with_nothing_to_sign_in_as(): void
    {
        $this->assertSame(0, User::query()->count());
        $this->assertNull(Season::query()->where('status', SeasonStatus::Active)->first());
    }

    /**
     * The whole point: the account that comes out can actually work. An
     * administrator with no assignment in an active season has no permissions
     * at all, so creating the user without the season would produce somebody who
     * can sign in and do nothing.
     */
    public function test_it_opens_the_first_season_and_an_administrator_who_can_work(): void
    {
        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->expectsConfirmation('Open the first season now?', 'yes')
            ->expectsQuestion('School year the season belongs to', '2026')
            ->expectsQuestion('Round number (the contest edition, for example 14)', '14')
            ->expectsQuestion('Season name', 'Season 2026')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $season = Season::query()->where('status', SeasonStatus::Active)->firstOrFail();

        $this->assertSame(14, $season->round_number);
        $this->assertSame(2026, $season->year);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->permissionsForActiveSeason()->isNotEmpty());
        $this->assertDatabaseHas('season_user_assignments', [
            'season_id' => $season->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_the_password_is_hashed_and_signs_in(): void
    {
        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->expectsConfirmation('Open the first season now?', 'yes')
            ->expectsQuestion('School year the season belongs to', '2026')
            ->expectsQuestion('Round number (the contest edition, for example 14)', '14')
            ->expectsQuestion('Season name', 'Season 2026')
            ->assertExitCode(0);

        $password = User::query()->where('email', 'admin@example.test')->value('password');

        $this->assertNotSame('correct-horse', $password);
        $this->assertTrue(password_verify('correct-horse', (string) $password));
    }

    /** Declining leaves nothing behind, rather than a powerless account. */
    public function test_declining_the_season_creates_no_account_at_all(): void
    {
        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->expectsConfirmation('Open the first season now?', 'no')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Season::query()->count());
    }

    public function test_an_existing_active_season_is_used_rather_than_a_second_one_opened(): void
    {
        $season = Season::query()->create([
            'name' => 'Season 2026', 'year' => 2026, 'round_number' => 14, 'status' => SeasonStatus::Active,
        ]);

        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->assertExitCode(0);

        $this->assertSame(1, Season::query()->count());
        $this->assertSame(
            $season->id,
            SeasonUserAssignment::query()->firstOrFail()->season_id,
        );
    }

    public function test_it_refuses_an_address_that_already_has_an_account(): void
    {
        User::query()->create(['name' => 'Someone', 'email' => 'admin@example.test', 'password' => 'whatever-8']);

        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
    }

    public function test_it_refuses_a_password_typed_differently_twice(): void
    {
        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-hoarse')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_a_password_shorter_than_the_rest_of_the_app_allows(): void
    {
        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsQuestion('Repeat the password', 'short')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    /** A round number is unique in the table; a clash must not reach the insert. */
    public function test_it_refuses_a_round_number_that_is_already_taken(): void
    {
        Season::query()->create([
            'name' => 'Old', 'year' => 2025, 'round_number' => 14, 'status' => SeasonStatus::Archived,
        ]);

        $this->artisan('soahtc:create-admin', ['--name' => 'Site Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse')
            ->expectsQuestion('Repeat the password', 'correct-horse')
            ->expectsConfirmation('Open the first season now?', 'yes')
            ->expectsQuestion('School year the season belongs to', '2026')
            ->expectsQuestion('Round number (the contest edition, for example 14)', '14')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(1, Season::query()->count());
    }
}
