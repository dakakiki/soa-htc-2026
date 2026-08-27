<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Enums\SeasonStatus;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Support\SeasonContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Opens the first administrator account on a fresh installation.
 *
 * Until 2026-08-27 the only administrator anywhere was the one `MasterDataSeeder`
 * creates, and that seeder refuses to run outside `local`/`testing` — so a fresh
 * production had no account at all, and nothing that could make one.
 *
 * 🪤 An account is not enough on its own. Permissions are read from the user's
 * assignment IN THE ACTIVE SEASON ({@see User::permissionsForActiveSeason}), and a
 * season is created only by the dev seeder, by the legacy archive import, or by a
 * rollover that needs an existing season and a permission to run. A fresh install
 * therefore has no season, so an administrator created without one would be able
 * to sign in and do nothing at all — including create the season that would fix
 * it. This command closes that circle: no active season, and it offers to open
 * one before it makes anybody an administrator.
 *
 * The password is asked for, never passed as an argument: an option would put it
 * in the shell history and in the process list of every user on the box.
 */
class CreateAdministrator extends Command
{
    protected $signature = 'soahtc:create-admin
        {--name= : The administrator\'s name}
        {--email= : The administrator\'s e-mail address}';

    protected $description = 'Open the first administrator account (and the first season, if there is none)';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?? '');
        $name = $name !== '' ? $name : (string) $this->ask('Name');

        $email = (string) ($this->option('email') ?? '');
        $email = $email !== '' ? $email : (string) $this->ask('E-mail address');

        $password = (string) $this->secret('Password (at least 8 characters)');
        $confirmation = (string) $this->secret('Repeat the password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                // `unique` rather than a friendly reuse: an existing account
                // already has a password somebody chose, and quietly resetting
                // it from a console command is not this command's business.
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $season = SeasonContext::active();

        if ($season === null && ($season = $this->openFirstSeason()) === null) {
            return self::FAILURE;
        }

        $role = Role::query()->where('key', SystemRole::Admin->value)->first();

        if ($role === null) {
            $this->error('The administrator role is missing. Run `php artisan db:seed --class=RolePermissionSeeder` first.');

            return self::FAILURE;
        }

        // One transaction: an account without its assignment is the useless
        // half of this, and that is exactly the state being fixed here.
        $user = DB::transaction(function () use ($name, $email, $password, $season, $role): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);

            SeasonUserAssignment::query()->create([
                'season_id' => $season->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
            ]);

            return $user;
        });

        $this->info('Administrator '.$user->email.' can now sign in.');
        $this->line('  Season: '.$season->name.' (round '.$season->round_number.', '.$season->year.')');

        return self::SUCCESS;
    }

    /**
     * Offer to open the first season, because without one the account would be
     * powerless. Declining is allowed and leaves nothing behind — better an
     * install with no administrator than one with an administrator who cannot
     * work and no obvious reason why.
     */
    private function openFirstSeason(): ?Season
    {
        $this->warn('There is no active season. An administrator takes their permissions from');
        $this->warn('their assignment in the active season, so without one this account could');
        $this->warn('sign in and do nothing — including opening a season.');

        if (! $this->confirm('Open the first season now?', true)) {
            $this->error('Nothing was created.');

            return null;
        }

        $year = (int) $this->ask('School year the season belongs to', (string) (int) date('Y'));
        $round = (int) $this->ask('Round number (the contest edition, for example 14)');

        $validator = Validator::make(
            ['year' => $year, 'round_number' => $round],
            [
                'year' => ['required', 'integer', 'min:2000', 'max:2100'],
                // Unique in the table, not merely among active ones: the column
                // carries a unique index and a clash would fail on insert.
                'round_number' => ['required', 'integer', 'min:1', 'unique:seasons,round_number'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        $name = (string) $this->ask('Season name', 'Season '.$year);

        return Season::query()->create([
            'name' => $name,
            'year' => $year,
            'round_number' => $round,
            'status' => SeasonStatus::Active,
        ]);
    }
}
