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
            ->assertJsonPath('data.colors.palette_1', '#fbba00')
            ->assertJsonCount(12, 'data.colors');
    }

    public function test_admin_can_update_colours(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/settings/theme', $this->colors('#0a0b0c'))
            ->assertOk()
            ->assertJsonPath('data.colors.primary', '#0a0b0c');

        $this->assertDatabaseHas('settings', ['id' => Setting::current()->id, 'color_primary' => '#0a0b0c']);
    }

    public function test_site_title_is_saved_and_served_publicly(): void
    {
        $title = '<p>SOA <strong>HTC</strong></p>';

        $this->actingAs($this->admin())
            ->putJson('/api/settings/theme', array_merge($this->colors(), ['site_title' => $title]))
            ->assertOk()
            ->assertJsonPath('data.site_title', $title);

        // The header renders it before login too, so it rides on the public payload.
        $this->getJson('/api/theme')->assertOk()->assertJsonPath('data.site_title', $title);
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

    public function test_logo_can_be_deleted(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->put('/api/settings/theme', array_merge($this->colors(), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]))
            ->assertOk();

        $path = Setting::current()->logo_path;
        $this->assertNotNull($path);

        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/theme/assets/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        $this->assertNull(Setting::current()->logo_path);
        Storage::disk('public')->assertMissing($path);

        // An unknown asset key is a 404, not a silent no-op.
        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/theme/assets/nope')
            ->assertNotFound();
    }

    public function test_svg_logo_is_stored_sanitized(): void
    {
        Storage::fake('public');

        $svg = <<<'SVG'
            <?xml version="1.0"?>
            <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 40" onload="alert(1)">
                <script type="text/javascript">alert(document.cookie)</script>
                <style>.a{fill:#003758}</style>
                <a xlink:href="javascript:alert(1)"><tspan>click</tspan></a>
                <rect class="a" width="120" height="40" fill="url(#g)"/>
                <foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(1)"/></body></foreignObject>
                <linearGradient id="g"><stop offset="0" stop-color="#fbba00"/></linearGradient>
            </svg>
            SVG;

        $this->actingAs($this->admin())
            ->put('/api/settings/theme', array_merge($this->colors(), [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
            ]))
            ->assertOk();

        $path = Setting::current()->logo_path;
        $this->assertNotNull($path);
        $this->assertStringEndsWith('.svg', $path);

        $stored = Storage::disk('public')->get($path);
        // Script, handlers, foreign markup and external links never reach the disk…
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringNotContainsString('foreignObject', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('DOCTYPE', $stored);
        // …while the artwork itself survives, gradient reference included.
        $this->assertStringContainsString('<rect', $stored);
        $this->assertStringContainsString('url(#g)', $stored);
        $this->assertStringContainsString('#fbba00', $stored);
    }

    public function test_svg_that_is_not_an_svg_document_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->put('/api/settings/theme', array_merge($this->colors(), [
                'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<html><body>nope</body></html>'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');

        $this->assertNull(Setting::current()->logo_path);
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

        $this->actingAs($user)->deleteJson('/api/settings/theme/assets/logo')->assertForbidden();
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

        $this->assertDatabaseHas('settings', [
            'id' => Setting::current()->id,
            'cert_body' => '<p>Hello [name]</p>',
            'cert_signature_text' => 'Jane Doe',
        ]);
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

    /**
     * One row, whatever id it happens to have (ADR-0069).
     *
     * 🪤 The counter is deliberately pushed off 1 first. `Setting::current()` used
     * to ask for the row with `id = 1` while `id` is not fillable — so the create
     * dropped it, the database assigned its own, and the next call went looking
     * for id 1 again and made ANOTHER row. Not two: a new one on every call.
     *
     * On a fresh SQLite database in memory the counter stands at 1, so the first
     * row landed there and the bug was invisible — which is exactly how it
     * survived. Burning an id here makes the test state the invariant on every
     * database rather than on the lucky one.
     */
    public function test_the_settings_row_is_a_singleton_whatever_id_it_lands_on(): void
    {
        Setting::query()->delete();
        Setting::query()->create([])->delete();

        $first = Setting::current();
        Setting::current();
        Setting::current();

        $this->assertSame(1, Setting::query()->count(), 'A second settings row was created.');
        $this->assertSame($first->id, Setting::current()->id, 'current() stopped returning the same row.');
    }
}
