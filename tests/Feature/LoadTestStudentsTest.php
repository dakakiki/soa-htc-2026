<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyLevel;
use App\Domain\Assessment\Models\Test as AssessmentTest;
use App\Domain\Competition\Models\Registration;
use App\Domain\Competition\Models\StudentSession;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The load-test fixtures: synthetic competitors that can sit a real exam, and an
 * undo that can be trusted on a server holding real names.
 */
class LoadTestStudentsTest extends TestCase
{
    use RefreshDatabase;

    private function aTestWithALevel(): AssessmentTest
    {
        $test = AssessmentTest::create(['title' => 'Load', 'duration' => 30, 'status' => 'active']);
        $test->levels()->attach(DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id);

        return $test;
    }

    public function test_it_creates_competitors_with_live_sessions_and_writes_their_tokens(): void
    {
        $this->seed();
        $test = $this->aTestWithALevel();

        $this->artisan('loadtest:students', ['--count' => 3, '--tag' => 'spec', '--test' => $test->id])
            ->assertSuccessful();

        $venue = School::where('name', 'SPEC — synthetic venue')->firstOrFail();
        $made = Registration::where('school_id', $venue->id)->get();

        $this->assertCount(3, $made);
        $this->assertCount(3, StudentSession::whereIn('registration_id', $made->pluck('id'))->get());

        // 🪤 Two markers, and the numbers sit outside any round's block — a real
        // one opens with the round number.
        foreach ($made as $registration) {
            $this->assertStringStartsWith('99', $registration->competitor_number);
            $this->assertSame(8, strlen($registration->competitor_number));
            $this->assertGreaterThan(1_000_000, $registration->sequence, 'the roster counts from 1');
        }

        $file = storage_path('app/loadtest/spec.json');
        $this->assertFileExists($file);

        $payload = json_decode((string) file_get_contents($file), true);
        $this->assertSame($test->id, $payload['test_id']);
        $this->assertCount(3, $payload['students']);
        $this->assertNotEmpty($payload['students'][0]['token']);

        // The token written out is the one the session accepts.
        $this->assertTrue(StudentSession::query()
            ->where('token_hash', hash('sha256', $payload['students'][0]['token']))
            ->active()->exists());

        @unlink($file);
    }

    /**
     * 🔴 The undo is the reason this command can be pointed at a server that
     * holds real names: it deletes only rows carrying BOTH markers, and it
     * leaves the venue standing if anything real is inside it.
     */
    public function test_cleanup_removes_only_what_it_made(): void
    {
        $this->seed();
        $test = $this->aTestWithALevel();

        $this->artisan('loadtest:students', ['--count' => 2, '--tag' => 'spec', '--test' => $test->id])
            ->assertSuccessful();

        $venue = School::where('name', 'SPEC — synthetic venue')->firstOrFail();

        // A real child, sitting in the same venue, whose number is a real one.
        $real = Registration::create([
            'season_id' => Season::where('round_number', 14)->value('id'),
            'competitor_number' => '14777777', 'sequence' => 777777,
            'school_id' => $venue->id, 'country_id' => Country::where('iso_alpha2', 'RS')->value('id'),
            'difficulty_level_id' => DifficultyLevel::where('level_short', 'H2')->firstOrFail()->id,
            'name' => 'A real child', 'date_of_birth' => '2010-05-01', 'grade' => 6, 'status' => 'active',
        ]);

        $this->artisan('loadtest:students', ['--cleanup' => true, '--tag' => 'spec'])->assertSuccessful();

        $this->assertSame(0, Registration::where('competitor_number', 'like', '99%')->count());
        $this->assertDatabaseHas('registrations', ['id' => $real->id]);
        $this->assertDatabaseHas('schools', ['id' => $venue->id], connection: null);

        @unlink(storage_path('app/loadtest/spec.json'));
    }

    public function test_cleanup_takes_the_venue_with_it_when_nothing_real_is_left(): void
    {
        $this->seed();
        $test = $this->aTestWithALevel();

        $this->artisan('loadtest:students', ['--count' => 2, '--tag' => 'spec', '--test' => $test->id])
            ->assertSuccessful();

        $this->artisan('loadtest:students', ['--cleanup' => true, '--tag' => 'spec'])->assertSuccessful();

        $this->assertDatabaseMissing('schools', ['name' => 'SPEC — synthetic venue']);

        @unlink(storage_path('app/loadtest/spec.json'));
    }
}
