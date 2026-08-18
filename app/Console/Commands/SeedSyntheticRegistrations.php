<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Support\SeasonContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only load fixture: bulk-inserts N synthetic registrations for the active
 * season across the existing schools/levels, so the registration list and the
 * competitor_number lookup can be measured at realistic volume. Not a migration
 * and never for production.
 */
class SeedSyntheticRegistrations extends Command
{
    protected $signature = 'registrations:seed-synthetic {count=100000}';

    protected $description = '[dev] Bulk-insert synthetic registrations to load-test the registration paths';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $season = SeasonContext::active();
        if ($season === null) {
            $this->error('No active season.');

            return self::FAILURE;
        }

        $schools = School::query()->get(['id', 'country_id']);
        $levelIds = DifficultyLevel::query()->pluck('id')->all();
        if ($schools->isEmpty() || $levelIds === []) {
            $this->error('Need at least one school and one difficulty level.');

            return self::FAILURE;
        }

        $count = (int) $this->argument('count');
        $start = (int) (DB::table('registrations')->where('season_id', $season->id)->max('sequence') ?? 0);
        $now = Carbon::now()->toDateTimeString();
        $schoolCount = $schools->count();
        $levelCount = count($levelIds);

        $this->info("Seeding {$count} registrations for season round {$season->round_number}…");
        $bar = $this->output->createProgressBar($count);

        $chunk = 2000;
        $buffer = [];
        for ($i = 1; $i <= $count; $i++) {
            $seq = $start + $i;
            $school = $schools[($i - 1) % $schoolCount];
            $grade = (($i - 1) % 13) + 1;
            $buffer[] = [
                'season_id' => $season->id,
                'competitor_number' => $season->round_number.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                'sequence' => $seq,
                'school_id' => $school->id,
                'country_id' => $school->country_id,
                'difficulty_level_id' => $levelIds[($i - 1) % $levelCount],
                'name' => 'Synthetic Student '.$seq,
                // Deterministic DOB derived from grade so the identify 3-factor
                // check works in load tests (real registrations always have one).
                'date_of_birth' => sprintf('%04d-05-01', 2019 - $grade),
                'grade' => $grade,
                'status' => 'active',
                'legacy_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($buffer) >= $chunk) {
                DB::table('registrations')->insert($buffer);
                $buffer = [];
                $bar->advance($chunk);
            }
        }
        if ($buffer !== []) {
            DB::table('registrations')->insert($buffer);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Total registrations now: '.DB::table('registrations')->count());

        return self::SUCCESS;
    }
}
