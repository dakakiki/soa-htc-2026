<?php

namespace Tests\Feature;

use App\Domain\Assessment\Models\DifficultyCategory;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Country;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DifficultyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@soahtc.test')->firstOrFail();
    }

    /** A user whose active-season role lacks `difficulty.manage`. */
    private function nonManager(): User
    {
        $season = Season::where('round_number', 14)->firstOrFail();
        $role = Role::where('key', SystemRole::SchoolCoordinator->value)->firstOrFail();
        $user = User::factory()->create();

        SeasonUserAssignment::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    public function test_admin_can_create_category_for_all_countries(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/difficulty-categories', [
                'name' => 'Regular X',
                'type' => 'regular',
                'countries_all' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'regular')
            ->assertJsonPath('data.countries_all', true)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_scope_category_to_countries(): void
    {
        $country = Country::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/difficulty-categories', [
                'name' => 'Regular Scoped',
                'type' => 'regular',
                'countries_all' => false,
                'country_ids' => [$country->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.countries_all', false)
            ->assertJsonPath('data.countries.0.id', $country->id);

        $this->assertDatabaseHas('difficulty_category_country', [
            'difficulty_category_id' => $response->json('data.id'),
            'country_id' => $country->id,
        ]);
    }

    public function test_countries_required_when_not_all(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/difficulty-categories', [
                'name' => 'Bad',
                'type' => 'regular',
                'countries_all' => false,
                'country_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country_ids');
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/difficulty-categories', [
                'name' => 'Bad',
                'type' => 'nope',
                'countries_all' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_admin_can_add_level_with_grades(): void
    {
        $category = DifficultyCategory::where('type', 'regular')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/difficulty-levels', [
                'difficulty_category_id' => $category->id,
                'name' => 'HIPPO 9',
                'level_short' => 'H9',
                'grades' => [12, 13],
                'position' => 9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.level_short', 'H9')
            ->assertJsonPath('data.grades', [12, 13]);
    }

    public function test_grade_out_of_range_is_rejected(): void
    {
        $category = DifficultyCategory::where('type', 'regular')->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/difficulty-levels', [
                'difficulty_category_id' => $category->id,
                'name' => 'Bad',
                'level_short' => 'BX',
                'grades' => [14],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grades.0');
    }

    public function test_category_status_can_be_toggled(): void
    {
        $category = DifficultyCategory::firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/difficulty-categories/{$category->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_category_with_levels_cannot_be_deleted(): void
    {
        // Seeded categories have levels.
        $category = DifficultyCategory::where('type', 'regular')->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/api/difficulty-categories/{$category->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('difficulty_categories', ['id' => $category->id]);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $category = DifficultyCategory::create([
            'name' => 'Empty',
            'type' => 'regular',
            'countries_all' => true,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/difficulty-categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('difficulty_categories', ['id' => $category->id]);
    }

    public function test_non_manager_is_forbidden(): void
    {
        $user = $this->nonManager();

        $this->actingAs($user)->getJson('/api/difficulty-categories')->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/difficulty-categories', ['name' => 'X', 'type' => 'regular', 'countries_all' => true])
            ->assertForbidden();
    }
}
