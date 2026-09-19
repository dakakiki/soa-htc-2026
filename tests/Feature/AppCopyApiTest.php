<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\AppCopy;
use App\Domain\Cms\Support\AppScreens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website → Mobile (ADR-0133): the installed application's own words.
 *
 * The whole screen rests on one promise — that a box left empty is the wording
 * the application ships, not an empty line — so most of what is asserted here is
 * about absence: no row stored, no default sent, nothing left behind.
 */
class AppCopyApiTest extends TestCase
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

    public function test_the_editor_is_given_the_screens_and_whatever_is_stored(): void
    {
        AppCopy::create(['key' => 'public.app.who', 'value' => 'Who is this?']);

        $response = $this->actingAs($this->admin())->getJson('/api/cms/app-screens');

        $response->assertOk();

        // Read out rather than asserted by path: the keys ARE dotted, and a
        // dotted path would go looking for a nested `public` → `app` → `who`.
        $this->assertSame(['public.app.who' => 'Who is this?'], $response->json('values'));

        $screens = $response->json('screens');

        $this->assertCount(count(AppScreens::all()), $screens);
        $this->assertSame('start', $screens[0]['key']);
        $this->assertSame('/app', $screens[0]['path']);
        $this->assertContains(
            'public.app.who',
            array_column($screens[0]['fields'], 'key'),
        );
    }

    /**
     * 🔴 The editor is sent no defaults, and the table holds none.
     *
     * The words a screen ships with live in the SPA's catalogue, which is the
     * same file the screen reads them from. A copy of them here would be a
     * second set to keep in step, and the first time the two disagreed the
     * editor would be advertising a default that no screen draws.
     */
    public function test_nothing_is_stored_for_a_line_nobody_has_rewritten(): void
    {
        $this->actingAs($this->admin())->getJson('/api/cms/app-screens')
            ->assertOk()
            ->assertJsonPath('values', []);

        $this->assertSame(0, AppCopy::query()->count());
    }

    public function test_a_screen_is_saved_a_screen_at_a_time(): void
    {
        $response = $this->actingAs($this->admin())->putJson('/api/cms/app-screens/start', [
            'values' => [
                'public.app.who' => 'Who has the phone?',
                'public.app.lead' => '',
                'public.app.studentNote' => '',
                'public.app.coordinatorNote' => '',
            ],
        ])->assertOk();

        $this->assertSame(['public.app.who' => 'Who has the phone?'], $response->json('values'));

        // Only the box that was filled leaves a row behind.
        $this->assertSame(1, AppCopy::query()->count());
    }

    /**
     * 🔴 Emptying a box is how the shipped wording comes back — so it deletes
     * the row rather than storing an empty string. A stored '' would be a screen
     * with a blank where its heading was, and nothing on the editor would tell
     * the two apart.
     */
    public function test_emptying_a_box_gives_the_original_back(): void
    {
        AppCopy::create(['key' => 'public.app.who', 'value' => 'Who is this?']);

        $this->actingAs($this->admin())->putJson('/api/cms/app-screens/start', [
            'values' => [
                'public.app.who' => '',
                'public.app.lead' => '',
                'public.app.studentNote' => '',
                'public.app.coordinatorNote' => '',
            ],
        ])->assertOk()->assertJsonPath('values', []);

        $this->assertSame(0, AppCopy::query()->count());
        $this->assertNull(AppCopy::query()->where('key', 'public.app.who')->first());
    }

    /** Whitespace is not a rewrite; it is an empty box with something in it. */
    public function test_a_box_holding_only_spaces_is_an_empty_box(): void
    {
        $this->actingAs($this->admin())->putJson('/api/cms/app-screens/start', [
            'values' => ['public.app.who' => '   '],
        ])->assertOk();

        $this->assertSame(0, AppCopy::query()->count());
    }

    /**
     * 🔴 A tab may only write its own lines. Without the check one screen could
     * save another screen's wording — or a key no screen reads at all, which
     * would then sit in the table with nothing left to show it.
     */
    public function test_a_screen_cannot_write_another_screens_line(): void
    {
        $this->actingAs($this->admin())->putJson('/api/cms/app-screens/start', [
            'values' => ['message.inbox' => 'Post'],
        ])->assertStatus(422);

        $this->assertSame(0, AppCopy::query()->count());
    }

    public function test_a_key_no_screen_offers_is_refused(): void
    {
        $this->actingAs($this->admin())->putJson('/api/cms/app-screens/start', [
            'values' => ['login.email' => 'Address'],
        ])->assertStatus(422);
    }

    public function test_an_unknown_screen_is_not_found(): void
    {
        $this->actingAs($this->admin())->putJson('/api/cms/app-screens/nowhere', [
            'values' => [],
        ])->assertNotFound();
    }

    /**
     * What the application reads at boot. Public because `/app` is: it is opened
     * by tapping an icon, before anybody has signed in or entered a candidate
     * number.
     */
    public function test_the_application_reads_the_overrides_without_signing_in(): void
    {
        AppCopy::create(['key' => 'public.app.who', 'value' => 'Who is this?']);

        $response = $this->getJson('/api/public/app-copy')->assertOk();

        $this->assertSame(['public.app.who' => 'Who is this?'], $response->json('values'));
    }

    /**
     * 🪤 A row whose key the registry has stopped offering is left behind by
     * design — nothing deletes it — so it is filtered on the way out as well as
     * on the way in. Otherwise the application would go on drawing a line the
     * editor no longer shows anybody, and there would be no screen on which to
     * find it.
     */
    public function test_a_line_no_longer_offered_is_not_served_to_the_application(): void
    {
        AppCopy::create(['key' => 'public.app.goneAway', 'value' => 'Orphan']);

        $this->getJson('/api/public/app-copy')
            ->assertOk()
            ->assertJsonPath('values', []);

        $this->actingAs($this->admin())->getJson('/api/cms/app-screens')
            ->assertOk()
            ->assertJsonPath('values', []);
    }

    /**
     * 🪤 An installation that has rewritten nothing is the ordinary case, and it
     * is the one PHP gets wrong on its own: an empty array goes out as `[]`,
     * where both clients are typed for a map. Nothing broke — a missing key
     * reads as `undefined` either way and every line falls back to the
     * catalogue — but a contract that is wrong in the common case is a contract
     * nobody checks against.
     */
    public function test_an_empty_set_of_overrides_is_sent_as_an_object(): void
    {
        $this->assertStringContainsString(
            '"values":{}',
            $this->getJson('/api/public/app-copy')->getContent(),
        );

        $this->assertStringContainsString(
            '"values":{}',
            $this->actingAs($this->admin())->getJson('/api/cms/app-screens')->getContent(),
        );
    }

    public function test_the_editor_is_closed_to_somebody_without_the_permission(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson('/api/cms/app-screens')->assertForbidden();
        $this->actingAs($outsider)->putJson('/api/cms/app-screens/start', ['values' => []])
            ->assertForbidden();
    }
}
