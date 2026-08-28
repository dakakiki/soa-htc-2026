<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Support\SeasonContext;
use App\Domain\Organization\Support\SeasonRollover;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Run the whole season rollover against the real data, then undo it (ADR-0065).
 *
 * Starting a season is the one irreversible thing this application does, it runs
 * once a year, and its archive step is an `INSERT … SELECT` across seven joins —
 * the shape a green SQLite suite says least about. So the way to know it works on
 * a given server is to run it there, on that server's data, and roll it back.
 *
 * That is safe because of what the rollover is made of: `INSERT`, `DELETE`,
 * `UPDATE` and nothing else. No DDL, and the wipe is `DELETE` rather than
 * `TRUNCATE`, which would commit implicitly and take the rehearsal with it. The
 * engine check below is what keeps that promise honest on a database this code
 * has never seen.
 *
 * What it answers that nothing else can: HOW LONG. The Settings → Season form
 * runs this inside an HTTP request, and 54 seconds was measured on the dev
 * machine against 50 004 registrations. A server whose PHP or proxy gives up
 * sooner would cut the request off in the middle of the year's one unrepeatable
 * operation.
 */
class RehearseSeasonRollover extends Command
{
    use ConfirmableTrait;

    protected $signature = 'season:rehearse {--force : Skip the confirmation prompt}';

    protected $description = 'Run a full season rollover against the real data inside a transaction, report how long it took, then roll it back. Changes nothing.';

    /**
     * Everything the rollover writes to. All of it must survive a ROLLBACK, or
     * the rehearsal is not a rehearsal.
     *
     * @return list<string>
     */
    private function tablesTouched(): array
    {
        return array_values(array_unique([
            ...SeasonRollover::WIPE_TABLES,
            'archive_registrations',
            'archive_registration_results',
            'archive_registration_qualifications',
            'seasons',
            'season_user_assignments',
            'assignment_schools',
            'users',
            'personal_access_tokens',
            'schools',
        ]));
    }

    /**
     * Refuse on any table a ROLLBACK would not restore.
     *
     * MyISAM ignores transactions silently: the rows would be gone and the
     * command would print a cheerful summary over the top of it. The legacy
     * database this project imports from used MyISAM for six tables, so a server
     * built by hand from that shape is not a hypothetical.
     *
     * @return list<string> offending "table (ENGINE)" descriptions
     */
    private function nonTransactionalTables(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        $rows = DB::select(
            'SELECT table_name AS t, engine AS e FROM information_schema.tables'
            .' WHERE table_schema = DATABASE() AND table_name IN ('
            .implode(',', array_fill(0, count($this->tablesTouched()), '?')).')',
            $this->tablesTouched(),
        );

        $bad = [];
        foreach ($rows as $row) {
            if (strtoupper((string) $row->e) !== 'INNODB') {
                $bad[] = $row->t.' ('.$row->e.')';
            }
        }

        return $bad;
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];

        foreach ([...$this->tablesTouched(), 'registration_qualifications'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function handle(): int
    {
        $season = SeasonContext::active();

        if ($season === null) {
            $this->error('There is no active season, so there is no rollover to rehearse.');

            return self::FAILURE;
        }

        if (($bad = $this->nonTransactionalTables()) !== []) {
            $this->error('Refusing to rehearse: these tables would not survive a rollback.');
            foreach ($bad as $line) {
                $this->line('  '.$line);
            }
            $this->line('Convert them to InnoDB first, or the rehearsal would be a real rollover.');

            return self::FAILURE;
        }

        // Read before the run: `archiveAndWipe` raises the limit for itself, and
        // the number worth reporting is the one the machine came with.
        $cliLimit = (int) ini_get('max_execution_time');

        $this->info('Rehearsing the rollover out of season '.$season->name.' (round '.$season->round_number.').');
        $this->line('Everything below is written and then rolled back. Nothing is kept.');
        $this->newLine();

        if (! $this->confirmToProceed('This writes the whole rollover to the database before undoing it.')) {
            return self::FAILURE;
        }

        $before = $this->snapshot();
        // Rolled back, so the number only has to be free of the unique on
        // `seasons.round_number` for the length of the rehearsal.
        $round = (int) Season::query()->max('round_number') + 1;

        $started = microtime(true);
        $applied = null;
        $inside = [];
        $newSeasonId = null;
        $adminKeptAccess = null;
        $failure = null;

        DB::beginTransaction();

        try {
            $result = SeasonRollover::start([
                'name' => 'Rehearsal (rolled back)',
                'year' => (int) $season->year + 1,
                'round_number' => $round,
            ], null);

            $applied = $result['applied'];
            $newSeasonId = $result['season']->id;
            $inside = $this->snapshot();

            // The question the rollover exists to answer: does anybody still hold
            // anything afterwards? Asked of a real administrator on this database.
            $admin = User::query()
                ->whereHas('seasonAssignments', fn ($q) => $q->where('season_id', $newSeasonId))
                ->first();
            $adminKeptAccess = $admin?->fresh()->hasPermission('settings.manage');
        } catch (\Throwable $e) {
            $failure = $e;
        } finally {
            DB::rollBack();
        }

        $elapsed = round(microtime(true) - $started, 1);

        if ($failure !== null) {
            $this->newLine();
            $this->error('The rollover FAILED on this database after '.$elapsed.'s — and was rolled back.');
            $this->line(get_class($failure).': '.$failure->getMessage());
            $this->line('This is what the rehearsal is for: it failed here rather than on the day.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['What', 'Rows'], [
            ['archived — roster', (string) $applied['archived_registrations']],
            ['archived — results', (string) $applied['archived_results']],
            ['archived — qualifications', (string) $applied['archived_qualifications']],
            ['wiped — registrations', (string) $applied['registrations']],
            ['wiped — attempts', (string) $applied['attempts']],
            ['wiped — registration_results', (string) $applied['registration_results']],
            ['deleted — school coordinators', (string) $applied['users_deleted']],
            ['deactivated — users', (string) $applied['users_deactivated']],
            ['deactivated — venues', (string) $applied['schools_deactivated']],
            ['ASSIGNMENTS MOVED', (string) ($applied['assignments_moved'] ?? 0)],
        ]);

        $stranded = $inside['season_user_assignments'] - (int) ($applied['assignments_moved'] ?? 0);

        $this->line('Assignments left on the old season: '.$stranded.' (must be 0 — that is what carries permissions across)');
        $this->line('Active seasons afterwards: '.DB::table('seasons')->where('status', 'active')->count().' (must be 1)');
        $this->line('An administrator still held settings.manage: '.match ($adminKeptAccess) {
            true => 'yes',
            false => 'NO — nobody would be able to use the site',
            default => 'no assignment found to ask',
        });

        $this->newLine();
        $this->info('It took '.$elapsed.' seconds.');
        // ASCII on purpose: this prints on whatever console the server has.
        $this->line('Settings > Season runs this inside an HTTP REQUEST. Compare that number against:');
        $this->line('  • the WEB max_execution_time (this CLI process reports '
            .($cliLimit === 0 ? 'no limit' : $cliLimit.'s').', which says nothing about the web SAPI);');
        $this->line('  • whatever sits in front of PHP — nginx fastcgi_read_timeout, Apache ProxyTimeout,');
        $this->line('    mod_fcgid IPCCommTimeout. The application cannot raise those.');

        $this->newLine();
        $drift = [];
        $after = $this->snapshot();
        foreach ($before as $table => $count) {
            if ($after[$table] !== $count) {
                $drift[] = $table.': '.$count.' → '.$after[$table];
            }
        }

        if ($drift !== []) {
            $this->error('THE ROLLBACK DID NOT RESTORE EVERYTHING. This database has been changed:');
            foreach ($drift as $line) {
                $this->line('  '.$line);
            }

            return self::FAILURE;
        }

        $this->info('Rolled back — every table is back to the row count it started with.');

        return self::SUCCESS;
    }
}
