<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

/**
 * The installed application's screens, and which of their words an
 * administrator may rewrite (ADR-0133).
 *
 * 🔴 This REVERSES a decision written into `en.ts` and into {@see LayoutZones}:
 * that the application's own screens carry no admin, because "every word here is
 * interface, not content" and an administrator editing a screen reached by
 * tapping an icon is editing the one screen nobody can open to check. The owner
 * asked for it anyway on 2026-09-19, and the reasoning that made the old rule
 * safe is kept rather than thrown away — see the three rules below.
 *
 * Rule 1 — every field is an OVERRIDE. The screens still call `t()` with these
 * same keys and `en.ts` still holds the words; a stored row only wins where one
 * exists. So an empty box is not an empty screen, and clearing one is how the
 * original comes back. This is why the table stores no defaults.
 *
 * Rule 2 — the key IS the i18n key. No second naming, nothing to keep in step,
 * and a key dropped from the catalogue simply stops being offered here.
 *
 * Rule 3 — what is NOT offered, and why each one is refused:
 *   - Form field labels and placeholders ("E-mail", "Candidate no", "Choose…").
 *     The owner's rule of 2026-08-25 still holds: an administrator renaming a
 *     field breaks a form rather than improving a page.
 *   - Anything carrying `{n}` or `{round}`. "{n} papers" without its `{n}` is a
 *     line that renders as itself, and the screen it breaks is on a phone in an
 *     exam room.
 *   - Accessible names (show/hide password), which are read aloud and are not
 *     copy.
 *   - `common.loading` and `app.name`, which belong to the whole application and
 *     are set elsewhere.
 *
 * 🪤 Where an override APPLIES is decided by the components, not here: only
 * `resources/js/pages/app` and `resources/js/components/app` read them, through
 * `useAppCopy()`. That is what keeps a rewrite of `login.submit` for the phone
 * from quietly changing the website's own sign-in button, which asks for the
 * very same key (owner, 2026-09-19: the change lands on the application only).
 */
final class AppScreens
{
    /**
     * Screen key => the tab it draws, and the keys it offers.
     *
     * The order is the order a person meets the screens: the two entry screens,
     * then the competitor's side, then the coordinator's.
     *
     * @return array<string, array{label: string, path: string, description: string, fields: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'start' => [
                'label' => 'Start',
                'path' => '/app',
                'description' => 'The first screen after the icon is tapped: who is holding the phone.',
                'fields' => [
                    'public.app.who' => 'Heading',
                    'public.app.lead' => 'Under the heading',
                    'public.app.studentNote' => 'Student row · note',
                    'public.app.coordinatorNote' => 'Coordinator row · note',
                ],
            ],
            'menu' => [
                'label' => 'Student menu',
                'path' => '/app/student',
                'description' => 'What the child came to do: sit the test, try a sample, look up a mark.',
                'fields' => [
                    'public.app.what' => 'Heading',
                    'public.app.start' => 'Start row',
                    'public.app.startNote' => 'Start row · note',
                    'public.app.sampleNote' => 'Sample row · note',
                ],
            ],
            'identify' => [
                'label' => 'Your details',
                'path' => '/app/identify',
                'description' => 'The three details, and the exam password on the competition stream. The field labels themselves are not editable.',
                'fields' => [
                    'public.app.titleCompetition' => 'Title · competition',
                    'public.app.titleSample' => 'Title · sample',
                    'public.app.details' => 'Section heading',
                    'public.app.competitionTest' => 'Rule above the password',
                    'public.app.passwordHelper' => 'Under the password',
                    'public.app.continue' => 'Button',
                    'student.access.starting' => 'Button · while it works',
                    'student.access.error' => 'When the details do not match',
                    'public.app.close' => 'Country picker · close',
                    'public.app.noCountry' => 'Country picker · nothing found',
                    'student.access.shutLead' => 'Nothing open · lead',
                    'student.access.shutCompetition' => 'Nothing open · competition',
                    'student.access.shutSample' => 'Nothing open · sample',
                    'student.access.shutTrySample' => 'Nothing open · sample row',
                    'student.access.shutCheckResults' => 'Nothing open · results row',
                ],
            ],
            'signin' => [
                'label' => 'Coordinator sign-in',
                'path' => '/app/login',
                'description' => 'The coordinator signs in with the website account. The e-mail and password labels are not editable.',
                'fields' => [
                    'public.app.signInLead' => 'Under the title',
                    'public.app.signInHelper' => 'Under the form',
                    'login.submit' => 'Button',
                    'login.submitting' => 'Button · while it works',
                    'login.forgot' => 'Forgotten password link',
                    'login.failed' => 'When sign-in fails',
                ],
            ],
            'tests' => [
                'label' => 'My tests',
                'path' => '/app/tests',
                'description' => 'What is open for this competitor, and what the screen says when nothing is.',
                'fields' => [
                    'student.dashboard.yourQuiz' => 'Section heading',
                    'student.dashboard.sequence' => 'Under the heading',
                    'student.dashboard.start' => 'Start button',
                    'student.dashboard.resume' => 'Continue button',
                    'student.dashboard.retake' => 'Try again button',
                    'student.dashboard.locked' => 'Locked chip',
                    'student.dashboard.opensLater' => 'Why it is locked',
                    'student.dashboard.noTests' => 'Exam with no tests',
                    'public.app.noTests' => 'Nothing open · heading',
                    'public.app.noTestsNote' => 'Nothing open · note',
                    'public.app.sampleWay' => 'Nothing open · sample row note',
                    'student.dashboard.error' => 'When it will not load',
                ],
            ],
            'results' => [
                'label' => 'My results',
                'path' => '/app/results',
                'description' => 'Marks that have been published, under the two names they are published under.',
                'fields' => [
                    'student.results.eyebrow' => 'Eyebrow',
                    'student.results.title' => 'Heading',
                    'student.results.contest' => 'Contest block',
                    'student.results.contestNote' => 'Contest block · when empty',
                    'student.results.practice' => 'Practice block',
                    'student.results.practiceNote' => 'Practice block · when empty',
                    'student.results.empty' => 'Nothing sat yet',
                    'public.app.noResultsNote' => 'Nothing published yet',
                    'public.app.backToTests' => 'Way back row',
                    'public.app.backToTestsNote' => 'Way back row · note',
                    'student.results.error' => 'When it will not load',
                ],
            ],
            'messages' => [
                'label' => 'Notices',
                'path' => '/app/messages',
                'description' => 'The inbox, and the offer to be notified on this device.',
                'fields' => [
                    'message.inbox' => 'Heading',
                    'message.inboxNoneWaiting' => 'When nothing is unread',
                    'message.inboxEmpty' => 'Empty inbox',
                    'message.inboxEmptyNote' => 'Empty inbox · note',
                    'message.inboxInYourMail' => 'Row sent by e-mail only',
                    'message.inboxMarkRead' => 'Mark as read',
                    'message.inboxPutAway' => 'Put away',
                    'message.inboxMore' => 'Load more',
                    'message.inboxLoadingMore' => 'Load more · while it works',
                    'message.pushOn' => 'Turn notifications on',
                    'message.pushOnNote' => 'Turn notifications on · note',
                    'message.pushOff' => 'Turn notifications off',
                    'message.pushIsOn' => 'When this device is subscribed',
                    'message.pushWorking' => 'While it works',
                    'message.pushFailed' => 'When it will not turn on',
                ],
            ],
            'welcome' => [
                'label' => 'Coordinator home',
                'path' => '/app/welcome',
                'description' => 'The three ways in. Each note is written twice: once for a coordinator with several venues, once for one with a single venue.',
                'fields' => [
                    'public.app.welcome' => 'Heading',
                    'public.app.welcomeLead' => 'Under the heading',
                    'public.app.upcomingNoteMany' => 'Upcoming · note, several venues',
                    'public.app.upcomingNoteOne' => 'Upcoming · note, one venue',
                    'public.app.inProgressNoteMany' => 'In progress · note, several venues',
                    'public.app.inProgressNoteOne' => 'In progress · note, one venue',
                    'public.app.resultsNoteMany' => 'Results · note, several venues',
                    'public.app.resultsNoteOne' => 'Results · note, one venue',
                    'public.app.waysHintMany' => 'Hint under the three · several venues',
                    'public.app.waysHintOne' => 'Hint under the three · one venue',
                    'public.app.noExams' => 'Nothing open',
                    'public.app.noExamsNote' => 'Nothing open · note',
                ],
            ],
            'venues' => [
                'label' => 'Venue picker',
                'path' => '/app/venues/…',
                'description' => 'Which room, for a coordinator who runs more than one. The search field itself is not editable.',
                'fields' => [
                    'public.app.whichVenue' => 'Heading',
                    'public.app.countryCoordinator' => 'Eyebrow',
                    'public.app.noVenues' => 'Nothing found',
                ],
            ],
            'figures' => [
                'label' => 'Venue figures',
                'path' => '/app/venues/…/…',
                'description' => 'The numbers for one room. The counts themselves are not offered — each carries its number inside the sentence.',
                'fields' => [
                    'public.app.upcomingLead' => 'Upcoming · lead',
                    'public.app.inProgressLead' => 'In progress · lead',
                    'public.app.resultsLead' => 'Results · lead',
                    'public.app.entered' => 'Figure · entered',
                    'public.app.started' => 'Figure · started',
                    'public.app.submitted' => 'Figure · submitted',
                    'public.app.average' => 'Figure · average',
                    'public.app.nobodySitting' => 'Strip · nobody sitting',
                    'public.app.allHandedIn' => 'Row · all handed in',
                    'public.app.liveEvery' => 'Strip · how often it refreshes',
                    'public.app.refreshNow' => 'Refresh button',
                    'public.app.anotherVenue' => 'Another venue row',
                    'public.app.upcomingEmpty' => 'Upcoming · empty',
                    'public.app.upcomingEmptyNote' => 'Upcoming · empty note',
                    'public.app.inProgressEmpty' => 'In progress · empty',
                    'public.app.inProgressEmptyNote' => 'In progress · empty note',
                    'public.app.nothingPublished' => 'Results · empty, a chosen venue',
                    'public.app.nothingPublishedMine' => 'Results · empty, their own venue',
                    'public.app.nothingPublishedNote' => 'Results · empty note',
                    'public.app.venueNotFound' => 'Venue not available',
                ],
            ],
            'shared' => [
                'label' => 'Across the app',
                'path' => '—',
                'description' => 'Words more than one screen uses. A change here shows up on every screen that says them, which is why they are on no single tab.',
                'fields' => [
                    'public.app.back' => 'Back',
                    'public.app.student' => 'Student',
                    'public.app.coordinator' => 'Coordinator',
                    'public.app.sample' => 'Sample exam row',
                    'public.app.results' => 'Check results row',
                    'public.app.wayUpcoming' => 'Upcoming',
                    'public.app.wayRunning' => 'In progress',
                    'public.app.wayResults' => 'Results',
                    'public.app.loadFailed' => 'When something will not load',
                    'student.dashboard.awaitingResult' => 'Awaiting result chip',
                    'student.dashboard.completedLabel' => 'Done chip',
                    'student.dashboard.signOut' => 'Sign out',
                    'message.inboxOpen' => 'Open your notices',
                ],
            ],
        ];
    }

    /** Whether a screen exists, for a route that takes one by name. */
    public static function has(string $screen): bool
    {
        return array_key_exists($screen, self::all());
    }

    /**
     * The keys one screen offers.
     *
     * @return list<string>
     */
    public static function keysFor(string $screen): array
    {
        return array_keys(self::all()[$screen]['fields'] ?? []);
    }

    /**
     * Every key the registry offers, across all screens.
     *
     * 🔴 The validation list. A key not here is refused on save, so a stored row
     * can never name a string no screen reads — which is otherwise exactly what
     * accumulates as the catalogue is edited.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $keys = [];

        foreach (self::all() as $screen) {
            foreach (array_keys($screen['fields']) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
