<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Support\SeasonContext;
use App\Domain\Organization\Support\SeasonRollover;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Validator;

/**
 * Close the season that is running and open the next one, from the command line.
 *
 * Settings → Season does the same thing, and did it alone until now. The trouble
 * is that it does it inside one HTTP request: `archiveAndWipe` took 54 seconds
 * against 50,000 registrations on the development machine, and the roster is now
 * 108,771. The application raises its own ceiling to 300 seconds (ADR-0065) and
 * can raise nothing above it — a web server's own timeout is not ours to set, and
 * on managed hosting it may not be the administrator's either.
 *
 * From here the request never exists, so no timeout in front of PHP applies. The
 * work, the archive and the audit entry are identical: this calls the same
 * {@see SeasonRollover::start()} the screen calls.
 *
 * 🔴 Nothing it does is reversible. It archives the roster, wipes the season,
 * deletes school coordinators and opens a new round — so it prints the whole plan
 * with its row counts and asks before writing a thing.
 */
class StartSeason extends Command
{
    use ConfirmableTrait;

    protected $signature = 'season:start
        {--name= : the new season\'s name, e.g. "Season 2027"}
        {--year= : its year}
        {--round= : its round number — prefixes every competitor number it issues}
        {--starts-at= : optional opening date}
        {--ends-at= : optional closing date}
        {--actor= : e-mail of the administrator doing this, for the audit trail}
        {--dry-run : print the plan and write nothing}
        {--force : skip the confirmation}';

    protected $description = 'Archive and wipe the running season, then open the next round';

    public function handle(): int
    {
        $outgoing = SeasonContext::active();
        $dryRun = (bool) $this->option('dry-run');

        $attributes = $this->attributes();

        if ($attributes === null) {
            return self::FAILURE;
        }

        $actor = $this->actor();

        if ($actor === false) {
            return self::FAILURE;
        }

        $this->plan($outgoing?->id, $attributes, $actor);

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('This archives and wipes the running season. It cannot be undone.')) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $result = SeasonRollover::start($attributes, $actor);
        $seconds = round(microtime(true) - $started, 1);

        $this->newLine();
        $this->info("Season {$result['season']->name} is open (round {$result['season']->round_number}). Took {$seconds}s.");

        $applied = $result['applied'];
        $this->line('  archived: '
            ."{$applied['archived_registrations']} registrations, "
            ."{$applied['archived_results']} results, "
            ."{$applied['archived_qualifications']} qualifications");

        if (isset($applied['assignments_moved'])) {
            $this->line("  assignments carried forward: {$applied['assignments_moved']}");
        }

        /*
         * Worth printing, because it is the number to compare against whatever
         * sits in front of PHP on this server. If the rollover ever approaches
         * the web timeout, this is the command that says so before a browser
         * finds out the hard way.
         */
        $this->line("  the same work through the browser would have to finish inside that server's own timeout");

        return self::SUCCESS;
    }

    /**
     * The new season's details, asked for when they were not given, and validated
     * by exactly the rules the screen uses — `confirm` excepted, which the console
     * confirmation replaces.
     *
     * @return array<string, mixed>|null
     */
    private function attributes(): ?array
    {
        $attributes = [
            'name' => $this->option('name') ?: $this->ask('Name for the new season'),
            'year' => $this->option('year') ?: $this->ask('Year'),
            'round_number' => $this->option('round') ?: $this->ask('Round number'),
            'starts_at' => $this->option('starts-at') ?: null,
            'ends_at' => $this->option('ends-at') ?: null,
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // Free, and it has to be: the round prefixes every competitor number
            // the season issues, and the archive already holds the old ones.
            'round_number' => ['required', 'integer', 'min:1', 'unique:seasons,round_number'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return $validator->validated();
    }

    /**
     * Who is doing this. The audit trail keeps a season start for good (ADR-0068),
     * and "nobody" is a poor answer to who opened a round — so an unattributed run
     * says so out loud rather than passing quietly.
     *
     * @return User|null|false false when the e-mail names no account
     */
    private function actor(): User|null|false
    {
        $email = $this->option('actor');

        if (! $email) {
            $this->warn('No --actor given: the audit trail will record this season start without a name.');

            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the e-mail {$email}.");

            return false;
        }

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function plan(?int $outgoingId, array $attributes, ?User $actor): void
    {
        $plan = SeasonRollover::plan($outgoingId);

        $this->newLine();
        $this->line($outgoingId === null
            ? 'No active season — nothing to archive; the new one simply opens.'
            : "Outgoing season id: {$outgoingId}");
        $this->line("Opening: {$attributes['name']} ({$attributes['year']}), round {$attributes['round_number']}");
        $this->line('Recorded as done by: '.($actor?->name ?? 'nobody'));

        $rows = [
            ['ARCHIVE', 'registrations (roster)', (string) $plan['archive']['registrations']],
            ['ARCHIVE', 'registration_results', (string) $plan['archive']['results']],
            ['ARCHIVE', 'registration_qualifications', (string) $plan['archive']['qualifications']],
        ];

        foreach ($plan['wipe'] as $table => $count) {
            $rows[] = ['WIPE', $table, (string) $count];
        }

        $rows[] = ['DELETE', 'users (school coordinators)', (string) $plan['accounts']['coordinators_deleted']];
        $rows[] = ['DEACTIVATE', 'users (non-admin, still active)', (string) $plan['accounts']['users_deactivated']];
        $rows[] = ['DEACTIVATE', 'schools (still active)', (string) $plan['accounts']['schools_deactivated']];

        $this->newLine();
        $this->table(['Action', 'Target', 'Rows'], $rows);
        $this->newLine();
    }
}
