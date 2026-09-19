<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The screen behind Website → Notifications (ADR-0132): the e-mail template's
 * own settings.
 *
 * 🔴 Every field is an OVERRIDE of something the mail already borrows, so the
 * endpoint answers with both — the stored value, usually empty, and beside it
 * what a letter sent this minute would actually carry. A screen that showed
 * only the first would leave an administrator unable to tell an empty box from
 * an empty header.
 */
class MailTemplateApiTest extends TestCase
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

    /**
     * 🪤 `settings.manage`, not the `cms.manage` its neighbours in the Website
     * group use. The link lives there but the values live on the settings
     * singleton, and SettingPolicy is what guards writing to it — so a
     * cms-only user must be refused here rather than at Save.
     */
    public function test_the_template_needs_the_settings_permission(): void
    {
        $this->getJson('/api/settings/mail-template')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/settings/mail-template')->assertForbidden();
        $this->actingAs(User::factory()->create())->putJson('/api/settings/mail-template', [])->assertForbidden();
    }

    public function test_it_answers_with_the_stored_value_and_what_the_letter_would_say(): void
    {
        Setting::current()->update(['site_title' => 'Hippo the Contest']);

        $this->actingAs($this->admin())->getJson('/api/settings/mail-template')
            ->assertOk()
            // Nothing stored…
            ->assertJsonPath('header_text', null)
            // …and yet the masthead is not blank.
            ->assertJsonPath('effective.header_text', 'Hippo the Contest')
            ->assertJsonPath('defaults.greeting', 'Hello {name},');
    }

    public function test_saving_the_wording_keeps_it_and_reports_it_back(): void
    {
        $this->actingAs($this->admin())->putJson('/api/settings/mail-template', [
            'mail_header_text' => 'Hippo — official mail',
            'mail_greeting' => 'Dear {name},',
            'mail_signoff' => "Warm regards,\nThe {site} team",
            'mail_footer_text' => 'Written for the letter.',
            'mail_footer_web' => 'soa-htc.org',
            'mail_footer_email' => 'info@soa-htc.org',
        ])
            ->assertOk()
            ->assertJsonPath('header_text', 'Hippo — official mail')
            ->assertJsonPath('footer_web', 'soa-htc.org')
            ->assertJsonPath('effective.header_text', 'Hippo — official mail');

        $this->assertDatabaseHas('settings', [
            'id' => Setting::current()->id,
            'mail_footer_email' => 'info@soa-htc.org',
        ]);
    }

    /** 🪤 An address, not free text: it is printed as a `mailto:` in every letter. */
    public function test_a_footer_address_that_is_not_an_address_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/settings/mail-template', ['mail_footer_email' => 'not an address'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mail_footer_email']);
    }

    /**
     * 🔴 Raster only, and the reason is the whole point of the field: a mail
     * client draws no SVG, so accepting one here would rebuild the blank header
     * this screen exists to fix.
     */
    public function test_a_vector_cannot_be_uploaded_as_the_mail_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->put('/api/settings/mail-template', [
            'mail_logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mail_logo']);
    }

    public function test_the_logo_is_stored_replaced_and_removed(): void
    {
        Storage::fake('public');

        $first = $this->actingAs($this->admin())->put('/api/settings/mail-template', [
            'mail_logo' => UploadedFile::fake()->image('first.png'),
        ])->assertOk()->json();

        $this->assertNotNull($first['logo_url']);
        $stored = (string) Setting::current()->mail_logo_path;
        Storage::disk('public')->assertExists($stored);

        // Replacing takes the old file with it rather than leaving it on disk.
        $this->actingAs($this->admin())->put('/api/settings/mail-template', [
            'mail_logo' => UploadedFile::fake()->image('second.png'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($stored);
        $replaced = (string) Setting::current()->mail_logo_path;
        $this->assertNotSame($stored, $replaced);

        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/mail-template/assets/logo')
            ->assertOk()
            ->assertJsonPath('logo_url', null);

        Storage::disk('public')->assertMissing($replaced);
        $this->assertNull(Setting::current()->mail_logo_path);
    }

    /** Anything but the one asset this template has. */
    public function test_an_unknown_asset_is_not_found(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/api/settings/mail-template/assets/signature')
            ->assertNotFound();
    }
}
