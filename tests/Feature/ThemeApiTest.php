<?php

namespace Tests\Feature;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Season;
use App\Domain\Organization\Models\SeasonUserAssignment;
use App\Domain\Organization\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThemeApiTest extends TestCase
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

    /** A user whose active-season role lacks `settings.manage`. */
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

    /** @return array<string, string> */
    private function colors(string $primary = '#123456'): array
    {
        $payload = [];
        foreach (Setting::COLOR_KEYS as $key) {
            $payload[$key] = '#ffffff';
        }
        $payload['color_primary'] = $primary;

        return $payload;
    }

    public function test_theme_endpoint_is_public_and_returns_colours(): void
    {
        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.colors.primary', '#2563eb')
            ->assertJsonCount(8, 'data.colors');
    }

    public function test_admin_can_update_colours(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/settings/theme', $this->colors('#0a0b0c'))
            ->assertOk()
            ->assertJsonPath('data.colors.primary', '#0a0b0c');

        $this->assertDatabaseHas('settings', ['id' => 1, 'color_primary' => '#0a0b0c']);
    }

    public function test_invalid_hex_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/settings/theme', $this->colors('not-a-hex'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('color_primary');
    }

    public function test_logo_upload_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->put('/api/settings/theme', array_merge($this->colors(), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]))
            ->assertOk()
            ->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && $url !== '');

        $path = Setting::current()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_image_logo_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/api/settings/theme', array_merge($this->colors(), [
                'logo' => UploadedFile::fake()->create('malware.exe', 10),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    public function test_non_manager_cannot_update_but_can_read(): void
    {
        $user = $this->nonManager();

        $this->actingAs($user)->getJson('/api/theme')->assertOk();

        $this->actingAs($user)
            ->putJson('/api/settings/theme', $this->colors())
            ->assertForbidden();
    }

    public function test_admin_reads_certificate_settings_with_default_body(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/settings/certificate')->assertOk();

        $this->assertStringContainsString('[name]', $res->json('body'));  // built-in default template
        $res->assertJsonPath('placeholders.0.tag', '[name]')              // legend supplied by the backend
            ->assertJsonPath('signature_text', null)
            ->assertJsonPath('logo_url', null);
    }

    public function test_admin_updates_certificate_body_and_signature(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/settings/certificate', ['cert_body' => '<p>Hello [name]</p>', 'cert_signature_text' => 'Jane Doe'])
            ->assertOk()
            ->assertJsonPath('body', '<p>Hello [name]</p>')
            ->assertJsonPath('signature_text', 'Jane Doe');

        $this->assertDatabaseHas('settings', ['id' => 1, 'cert_body' => '<p>Hello [name]</p>', 'cert_signature_text' => 'Jane Doe']);
    }

    public function test_certificate_asset_upload_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->put('/api/settings/certificate', [
                'cert_body' => '<p>[name]</p>',
                'cert_signature_text' => '',
                'cert_logo' => UploadedFile::fake()->image('cert-logo.png'),
                'cert_qr' => UploadedFile::fake()->image('qr.png'),
            ])
            ->assertOk()
            ->assertJsonPath('logo_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonPath('qr_url', fn ($url) => is_string($url) && $url !== '');

        $path = Setting::current()->cert_logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_certificate_asset_can_be_deleted(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->put('/api/settings/certificate', [
                'cert_body' => '<p>[name]</p>',
                'cert_signature_text' => '',
                'cert_logo' => UploadedFile::fake()->image('cert-logo.png'),
            ])
            ->assertOk();

        $path = Setting::current()->cert_logo_path;
        $this->assertNotNull($path);

        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/certificate/assets/logo')
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        $this->assertNull(Setting::current()->cert_logo_path);
        Storage::disk('public')->assertMissing($path);

        // An unknown asset key is a 404, not a silent no-op.
        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/certificate/assets/nope')
            ->assertNotFound();
    }

    public function test_certificate_non_image_upload_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/api/settings/certificate', [
                'cert_body' => '<p>[name]</p>',
                'cert_signature_text' => '',
                'cert_logo' => UploadedFile::fake()->create('malware.exe', 10),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cert_logo');
    }

    public function test_non_manager_cannot_read_or_update_certificate(): void
    {
        $user = $this->nonManager();

        $this->actingAs($user)->getJson('/api/settings/certificate')->assertForbidden();
        $this->actingAs($user)->putJson('/api/settings/certificate', ['cert_body' => '<p>x</p>'])->assertForbidden();
        $this->actingAs($user)->deleteJson('/api/settings/certificate/assets/logo')->assertForbidden();
    }

    public function test_settings_row_is_a_singleton(): void
    {
        Setting::current();
        Setting::current();

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame(1, Setting::current()->id);
    }
}
