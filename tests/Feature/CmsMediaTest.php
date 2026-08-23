<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\Media;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\School;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CmsMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    public function test_the_library_is_closed_to_everyone_but_the_admin(): void
    {
        $this->getJson('/api/cms/media')->assertUnauthorized();

        $school = School::query()->firstOrFail();
        $season = Season::where('round_number', 14)->firstOrFail();
        $coordinator = User::factory()->create(['country_id' => $school->country_id]);
        $assignment = SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $coordinator->id,
            'role_id' => Role::where('key', SystemRole::CountryCoordinator->value)->value('id'),
            'status' => 'active',
        ]);
        $assignment->schools()->sync([$school->id]);

        $this->actingAs($coordinator)->getJson('/api/cms/media')->assertForbidden();
    }

    public function test_several_files_upload_in_one_request_and_keep_their_dimensions(): void
    {
        $response = $this->actingAs($this->admin())->post('/api/cms/media', [
            'files' => [
                UploadedFile::fake()->image('first.jpg', 640, 480),
                UploadedFile::fake()->image('second.png', 200, 200),
            ],
        ])->assertCreated();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);

        $first = Media::where('original_name', 'first.jpg')->firstOrFail();
        $this->assertSame(640, $first->width);
        $this->assertSame(480, $first->height);
        $this->assertSame($this->admin()->id, $first->uploaded_by);
        Storage::disk('public')->assertExists($first->path);
    }

    public function test_alt_text_is_editable(): void
    {
        $this->actingAs($this->admin())->post('/api/cms/media', [
            'files' => [UploadedFile::fake()->image('logo.png')],
        ])->assertCreated();

        $media = Media::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/cms/media/{$media->id}", ['alt' => 'The contest logo'])
            ->assertOk()
            ->assertJsonPath('data.alt', 'The contest logo');
    }

    public function test_deleting_removes_the_file_as_well_as_the_row(): void
    {
        $this->actingAs($this->admin())->post('/api/cms/media', [
            'files' => [UploadedFile::fake()->image('gone.png')],
        ])->assertCreated();

        $media = Media::query()->firstOrFail();
        $path = $media->path;

        $this->actingAs($this->admin())->deleteJson("/api/cms/media/{$media->id}")->assertNoContent();

        $this->assertDatabaseCount('cms_media', 0);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_page_carries_a_featured_image_that_can_be_removed_on_its_own(): void
    {
        $page = $this->actingAs($this->admin())->post('/api/cms/pages', [
            'title' => 'With A Picture',
            'image' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertCreated()->json('data');

        $this->assertNotNull($page['image_url']);

        $this->actingAs($this->admin())
            ->deleteJson("/api/cms/pages/{$page['id']}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        // The page itself is untouched.
        $this->assertDatabaseHas('cms_pages', ['id' => $page['id'], 'title' => 'With A Picture']);
    }
}
