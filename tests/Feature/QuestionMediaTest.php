<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Support\QuestionMedia;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * An exam's pictures and its listening audio are not public.
 *
 * Until 2026-08-28 they were: `QuestionController` stored them on the `public`
 * disk and both the admin resource and the exam payload quoted them as
 * `{APP_URL}/storage/questions/…`. The question LIST was gated by difficulty
 * level, but nothing gated the bytes — the material of a live contest came down
 * to anybody who asked, with no session at all.
 *
 * These tests hold the two halves of the answer: the files live where nothing
 * serves them, and each of the two callers gets in only by its own means.
 */
class QuestionMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake(QuestionMedia::DISK);
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    private function nonManager(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();
        SeasonUserAssignment::create(['season_id' => $season->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    /**
     * A question carrying a real file on the private disk, built without the
     * endpoint on purpose: `actingAs` signs the whole test in, not one request,
     * so uploading through the API here would leave the tests about being turned
     * away quietly signed in as an administrator. The upload path has its own
     * test below.
     */
    private function questionWithImage(): Question
    {
        $question = Question::create([
            'title' => 'Look at this', 'question_type' => 'essay', 'points' => 1, 'status' => 'active',
        ]);

        $question->forceFill([
            'image_path' => UploadedFile::fake()->image('q.png', 20, 20)
                ->store(QuestionMedia::DIRECTORY, QuestionMedia::DISK),
        ])->save();

        return $question;
    }

    private function staffUrl(Question $question, string $kind = 'image'): string
    {
        return "/api/questions/{$question->id}/media/{$kind}";
    }

    public function test_the_upload_lands_on_the_private_disk_and_nothing_on_the_public_one(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/questions', [
                'title' => 'Look at this',
                'question_type' => 'essay',
                'points' => 1,
                'image' => UploadedFile::fake()->image('q.png', 20, 20),
                'audio' => UploadedFile::fake()->create('q.mp3', 8, 'audio/mpeg'),
            ])
            ->assertCreated();

        $question = Question::query()->latest('id')->firstOrFail();

        $this->assertNotNull($question->image_path);
        $this->assertNotNull($question->audio_path);

        foreach ([$question->image_path, $question->audio_path] as $path) {
            Storage::disk(QuestionMedia::DISK)->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_the_resource_quotes_the_staff_route_rather_than_a_file_address(): void
    {
        $question = $this->questionWithImage();

        $url = (string) $this->actingAs($this->admin())
            ->getJson("/api/questions/{$question->id}")
            ->assertOk()
            ->json('data.image_url');

        // The point of the whole round: no `/storage/…` anywhere in the answer.
        $this->assertStringContainsString("/api/questions/{$question->id}/media/image", $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    public function test_a_stranger_is_turned_away(): void
    {
        $question = $this->questionWithImage();

        $this->getJson($this->staffUrl($question))->assertUnauthorized();
    }

    public function test_a_signed_in_user_without_the_permission_is_turned_away(): void
    {
        $question = $this->questionWithImage();

        $this->actingAs($this->nonManager())
            ->getJson($this->staffUrl($question))
            ->assertForbidden();
    }

    public function test_an_administrator_gets_the_bytes(): void
    {
        $question = $this->questionWithImage();

        $response = $this->actingAs($this->admin())->get($this->staffUrl($question));

        $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame(
            Storage::disk(QuestionMedia::DISK)->get($question->image_path),
            $response->baseResponse->getFile()->getContent(),
        );

        // `BinaryFileResponse` marks itself `public` unless told otherwise, and
        // a shared cache holding exam material is exactly what must not happen.
        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_an_unsigned_competitor_address_is_refused(): void
    {
        $question = $this->questionWithImage();

        $this->get("/api/student/questions/{$question->id}/media/image")->assertForbidden();
    }

    public function test_a_tampered_competitor_address_is_refused(): void
    {
        $question = $this->questionWithImage();

        $url = (string) QuestionMedia::signedUrl($question, 'image', now()->addHour());

        // Same signature, a different question: exactly what an attacker holding
        // one valid address would try next.
        $other = Question::create(['title' => 'Other', 'question_type' => 'essay', 'points' => 1, 'status' => 'active']);
        $other->forceFill(['image_path' => $question->image_path])->save();

        $this->get(str_replace(
            "/questions/{$question->id}/media",
            "/questions/{$other->id}/media",
            $url,
        ))->assertForbidden();
    }

    public function test_an_expired_competitor_address_is_refused(): void
    {
        $question = $this->questionWithImage();

        $url = (string) URL::temporarySignedRoute(
            'student.questions.media',
            now()->subMinute(),
            ['question' => $question->id, 'kind' => 'image'],
        );

        $this->get($url)->assertForbidden();
    }

    public function test_a_signed_competitor_address_serves_the_bytes(): void
    {
        $question = $this->questionWithImage();

        $response = $this->get((string) QuestionMedia::signedUrl($question, 'image', now()->addHour()));

        $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame(
            Storage::disk(QuestionMedia::DISK)->get($question->image_path),
            $response->baseResponse->getFile()->getContent(),
        );

        // `BinaryFileResponse` marks itself `public` unless told otherwise, and
        // a shared cache holding exam material is exactly what must not happen.
        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_a_kind_the_question_does_not_have_is_not_found(): void
    {
        $question = $this->questionWithImage();

        $this->actingAs($this->admin())
            ->getJson($this->staffUrl($question, 'audio'))
            ->assertNotFound();
    }

    public function test_a_kind_that_is_not_a_kind_is_not_a_route(): void
    {
        $question = $this->questionWithImage();

        $this->actingAs($this->admin())
            ->getJson($this->staffUrl($question, 'answers'))
            ->assertNotFound();
    }

    public function test_a_row_pointing_at_a_file_that_is_gone_is_not_found(): void
    {
        $question = $this->questionWithImage();
        Storage::disk(QuestionMedia::DISK)->delete($question->image_path);

        $this->actingAs($this->admin())
            ->getJson($this->staffUrl($question))
            ->assertNotFound();
    }

    public function test_deleting_the_question_takes_the_file_with_it(): void
    {
        $question = $this->questionWithImage();
        $path = $question->image_path;

        $this->actingAs($this->admin())
            ->deleteJson("/api/questions/{$question->id}")
            ->assertNoContent();

        Storage::disk(QuestionMedia::DISK)->assertMissing($path);
    }

    /** The file mover, straight from disk — anonymous class, so it has no name to import. */
    private function migration(): object
    {
        return require database_path('migrations/2026_08_28_120000_move_question_media_off_the_public_disk.php');
    }

    public function test_the_migration_carries_an_existing_file_to_the_private_disk(): void
    {
        $path = QuestionMedia::DIRECTORY.'/already-uploaded.png';
        Storage::disk('public')->put($path, 'the bytes');

        $this->migration()->up();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame('the bytes', Storage::disk(QuestionMedia::DISK)->get($path));
    }

    public function test_the_migration_puts_them_back(): void
    {
        $path = QuestionMedia::DIRECTORY.'/already-uploaded.png';
        Storage::disk(QuestionMedia::DISK)->put($path, 'the bytes');

        $this->migration()->down();

        Storage::disk(QuestionMedia::DISK)->assertMissing($path);
        $this->assertSame('the bytes', Storage::disk('public')->get($path));
    }

    public function test_the_migration_keeps_the_original_when_the_copy_does_not_arrive(): void
    {
        $path = QuestionMedia::DIRECTORY.'/already-uploaded.png';
        Storage::disk('public')->put($path, 'the bytes');

        // A destination that refuses the write the way a full volume does with
        // `throw => false`: returns false, raises nothing, logs nothing. Delete
        // the source after that and the only copy is gone while `migrate` says
        // DONE — so the migration has to stop instead.
        $refuses = Mockery::mock(FilesystemAdapter::class);
        $refuses->shouldReceive('fileExists')->andReturnFalse();
        $refuses->shouldReceive('writeStream')->andReturnFalse();
        Storage::set(QuestionMedia::DISK, $refuses);

        // Not `fail()` inside the try: PHPUnit's own failure is a RuntimeException
        // too, so the catch below would swallow it and report the wrong thing.
        $thrown = null;

        try {
            $this->migration()->up();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The migration finished instead of refusing to delete the only copy.');
        $this->assertStringContainsString('Nothing was deleted', $thrown->getMessage());
        $this->assertSame('the bytes', Storage::disk('public')->get($path));
    }

    public function test_replacing_the_file_removes_the_one_it_replaces(): void
    {
        $question = $this->questionWithImage();
        $first = $question->image_path;

        $this->actingAs($this->admin())
            ->post("/api/questions/{$question->id}", [
                '_method' => 'PUT',
                'question_type' => 'essay',
                'points' => 1,
                'image' => UploadedFile::fake()->image('other.png', 20, 20),
            ])
            ->assertOk();

        $second = $question->refresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk(QuestionMedia::DISK)->assertMissing($first);
        Storage::disk(QuestionMedia::DISK)->assertExists($second);
    }
}
