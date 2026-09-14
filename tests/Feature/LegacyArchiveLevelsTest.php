<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rounds up to 2023 name a difficulty level by the OLD scheme's own code, not by
 * a row id in `difficulty_category_levels` — that table was only created in
 * 11.2023. The two overlap on the bare digits and mean different things there:
 * old `2` is HIPPO 2, id 2 is Baby Hippo.
 *
 * Joining one against the other does not fail, which is why it stood: it answers
 * two levels too easy, and drops old `1` — the largest group of all — to nothing,
 * because the new table has no id 1. Half of rounds 9, 10 and 11 came out empty
 * and the other half came out wrong.
 */
class LegacyArchiveLevelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->giveTheLegacyConnectionSomewhereToLive();
    }

    public function test_an_old_scheme_round_is_read_by_the_old_scheme(): void
    {
        // `L1` appears nowhere in the current scheme, and is what settles which of
        // the two this table speaks — the digits beside it could be either.
        $this->legacyRoster([
            ['1', 'HIPPO 1 by its own name'],
            ['2', 'HIPPO 2, not Baby Hippo'],
            ['3', 'HIPPO 3, not Little Hippo'],
            ['4', 'HIPPO 4, not HIPPO 1'],
            ['5', 'HIPPO 5, not HIPPO 2'],
            ['L1', 'Little Hippo'],
            [' S 15 ', 'spacing is not a level'],
        ]);

        $this->artisan('legacy:archive-round el_student_bekap2021 --round=9')->assertSuccessful();

        $levels = DB::table('archive_registrations')->where('round_number', 9)
            ->orderBy('competitor_number')->pluck('level', 'competitor_number');

        $this->assertSame('H1', $levels['90000001']);
        $this->assertSame('H2', $levels['90000002']);
        $this->assertSame('H3', $levels['90000003']);
        $this->assertSame('H4', $levels['90000004']);
        $this->assertSame('H5', $levels['90000005']);
        $this->assertSame('LH', $levels['90000006']);
        // Kept under its own name: the three old Special levels were defined by
        // year of birth, and today's five bands are not the same thing.
        $this->assertSame('S15', $levels['90000007']);

        // Baby Hippo did not exist under that scheme, so nothing may carry it.
        $this->assertNotContains('BH', $levels->all());
        // And nothing is left without a level — every old code has a meaning.
        $this->assertNotContains(null, $levels->all());
    }

    public function test_a_current_scheme_round_still_joins_by_id(): void
    {
        // No code that only the old scheme used, so these digits are row ids.
        $this->legacyRoster([['2', 'id 2'], ['3', 'id 3'], ['4', 'id 4']], 'el_student_bekap2025');

        $this->artisan('legacy:archive-round el_student_bekap2025 --round=13')->assertSuccessful();

        $levels = DB::table('archive_registrations')->where('round_number', 13)
            ->orderBy('competitor_number')->pluck('level', 'competitor_number');

        $this->assertSame('BH', $levels['1300000001']);
        $this->assertSame('LH', $levels['1300000002']);
        $this->assertSame('H1', $levels['1300000003']);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $levels  [legacy value, why it is here]
     */
    private function legacyRoster(array $levels, string $table = 'el_student_bekap2021'): void
    {
        $prefix = $table === 'el_student_bekap2021' ? '9000000' : '130000000';

        foreach ($levels as $i => [$value]) {
            DB::connection('legacy')->table($table)->insert([
                'entry_id' => $i + 1,
                'student_id' => $prefix.($i + 1),
                'country_id' => 1,
                'level' => $value,
                'class' => 5,
            ]);
        }
    }

    private function giveTheLegacyConnectionSomewhereToLive(): void
    {
        config(['database.connections.legacy' => config('database.connections.'.config('database.default'))]);
        DB::purge('legacy');

        foreach (['el_student_bekap2021', 'el_student_bekap2025', 'el_country', 'difficulty_category_levels', 'schools', 'regions'] as $t) {
            Schema::connection('legacy')->dropIfExists($t);
        }

        foreach (['el_student_bekap2021', 'el_student_bekap2025'] as $t) {
            Schema::connection('legacy')->create($t, function ($table) {
                $table->unsignedBigInteger('entry_id');
                $table->string('student_id')->nullable();
                $table->unsignedBigInteger('school_id')->nullable();
                $table->unsignedInteger('country_id')->nullable();
                $table->string('level')->nullable();
                $table->integer('class')->nullable();
            });
        }

        Schema::connection('legacy')->create('el_country', function ($table) {
            $table->unsignedInteger('country_id');
            $table->string('country_name')->nullable();
        });
        DB::connection('legacy')->table('el_country')->insert(['country_id' => 1, 'country_name' => 'Serbia']);

        Schema::connection('legacy')->create('schools', function ($table) {
            $table->unsignedBigInteger('id');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
        });
        Schema::connection('legacy')->create('regions', function ($table) {
            $table->unsignedBigInteger('id');
            $table->string('name')->nullable();
        });

        // The current scheme, as it has stood since 11.2023: id 2 is Baby Hippo.
        Schema::connection('legacy')->create('difficulty_category_levels', function ($table) {
            $table->unsignedBigInteger('id');
            $table->string('level_short')->nullable();
        });
        foreach ([2 => 'BH', 3 => 'LH', 4 => 'H1', 5 => 'H2', 6 => 'H3', 7 => 'H4', 8 => 'H5'] as $id => $short) {
            DB::connection('legacy')->table('difficulty_category_levels')->insert(['id' => $id, 'level_short' => $short]);
        }
    }
}
