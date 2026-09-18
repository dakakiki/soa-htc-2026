<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The compose screen shows what a message will look like on each way it
 * travels — and the right-hand column is the whole reason that screen is worth
 * having: the administration writes once and the same words land in three
 * different shapes.
 *
 * 🪤 This exists because the rule broke the first time it was tested by a human.
 * Push was added as a checkbox on 2026-09-18 and the preview beside it was not,
 * so the column quietly stopped answering the question it is there for — and
 * nothing anywhere said so. The owner noticed, which is the wrong way round.
 *
 * The front has no test runner and is not getting one (ADR-0074, withdrawn), so
 * this reads the file, as `ManifestTest` reads the router.
 */
class EveryChannelIsPreviewedTest extends TestCase
{
    public function test_every_channel_that_can_be_ticked_is_also_previewed(): void
    {
        $form = $this->form();

        $channels = $this->tickableChannels($form);

        $this->assertNotEmpty($channels, 'No channel checkboxes found — has the form moved?');

        foreach ($channels as $channel) {
            $this->assertTrue(
                str_contains($form, 'v-if="form.'.$channel.'" class="border-b'),
                "The compose screen lets somebody tick `{$channel}` and then does not show them what "
                .'it will look like. Every channel in the left column needs its panel in the right one.',
            );
        }
    }

    /**
     * 🔴 And each preview draws the body the CHANNEL actually carries. A
     * notification is drawn by the operating system out of plain text — markup
     * reaches it as characters — so previewing the rich text there would promise
     * something the phone cannot do.
     */
    public function test_the_notification_preview_shows_the_plain_text_and_not_the_rich_text(): void
    {
        $form = $this->form();

        $panel = $this->panelFor($form, 'push');

        $this->assertStringContainsString('form.body', $panel);
        $this->assertStringNotContainsString('form.body_html', $panel, 'a phone cannot draw markup');
    }

    /** @return list<string> */
    private function tickableChannels(string $form): array
    {
        preg_match_all('/v-model="form\.([a-z_]+)"\s+type="checkbox"/', $form, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** The right-column block for one channel, up to the next one. */
    private function panelFor(string $form, string $channel): string
    {
        $at = strpos($form, 'v-if="form.'.$channel.'" class="border-b');

        $this->assertNotFalse($at, "No preview panel for {$channel}.");

        $next = strpos($form, 'class="border-b', $at + 20);

        return $next === false ? substr($form, $at) : substr($form, $at, $next - $at);
    }

    private function form(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/messages/MessageFormPage.vue'));
    }
}
