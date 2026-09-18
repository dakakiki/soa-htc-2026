<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A message that is only a letter is written once.
 *
 * 🔴 The owner's call of 2026-09-18 — *„kada se salje samo mail, sakri message
 * polje i posalji ga praznog"* — taking back the cost of ADR-0122: every message
 * stands in the inbox, the inbox row carries the plain text, so a long formal
 * mail had to be accompanied by a second short one written for a screen the
 * administrator was not addressing.
 *
 * The server holds the rule (`MessageController::validated`, and the tests in
 * `MessageApiTest` beside it). What is left here is what only the front can get
 * wrong, and the front has no test runner (ADR-0074, withdrawn).
 */
class MailOnlyNeedsNoShortTextTest extends TestCase
{
    /**
     * 🪤 `needsBody` was the constant `true` and reads like one that could go
     * back to being it — a computed that always answers the same thing invites
     * being folded away. It is a question now, and the answer is no for exactly
     * one combination of channels.
     */
    public function test_the_plain_text_field_is_hidden_when_the_letter_is_the_message(): void
    {
        $form = $this->form();

        $this->assertStringContainsString(
            'const needsBody = computed(() => !form.mail || form.push);',
            $form,
            'The compose screen asks for a short plain text again whatever the channels are. '
            .'A letter alone carries no in-app line, so there is nothing to write it for.',
        );
    }

    /**
     * 🔴 And the app panel says so rather than showing a blank. The preview is
     * the whole reason that column exists: an administrator has to see the row
     * a coordinator will get BEFORE sending, and for a letter that row is its
     * subject and a line pointing at the mail.
     */
    public function test_the_app_preview_shows_what_a_letter_looks_like_in_the_inbox(): void
    {
        $this->assertStringContainsString(
            'message.inboxInYourMail',
            $this->form(),
            'the in-app preview draws an empty body for a mail-only message',
        );
    }

    /**
     * Every screen a notice can be met on stands when there is no plain text:
     * subject, then where the words are. A screen that simply drew the empty
     * body would show a bold line over nothing and read as a fault.
     *
     * 🔴 Two screens, not three. Welcome was the third until 2026-09-18, when
     * the owner took the notices off it — the bell carries the count and the
     * inbox is one tap away, so the cards there were a second copy of a screen
     * that already exists.
     */
    public function test_every_screen_that_shows_a_notice_survives_an_empty_body(): void
    {
        foreach ([
            'resources/js/pages/app/MyMessagesPage.vue',
            'resources/js/pages/messages/InboxPage.vue',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));

            $this->assertMatchesRegularExpression(
                '/v-else-if="(row|notice)\.by_mail"/',
                $source,
                $path.' draws the plain body with nothing to fall back on. A mail-only notice '
                .'would stand there as a subject over an empty space.',
            );
        }
    }

    private function form(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/messages/MessageFormPage.vue'));
    }
}
