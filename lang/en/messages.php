<?php

/*
 * Domain-facing English strings. All user-visible text flows through language
 * files (never hard-coded in controllers, resources or Vue components).
 */

return [
    'season' => [
        'status' => [
            'draft' => 'Draft',
            'active' => 'Active',
            'archived' => 'Archived',
        ],
    ],
    'role' => [
        'admin' => 'Administrator',
        'country_coordinator' => 'Country coordinator',
        'school_coordinator' => 'School coordinator',
        'in_use' => 'This role is assigned to users and cannot be deleted.',
    ],
    'assignment' => [
        'duplicate' => 'This user already holds that role in the selected season.',
        'school_single' => 'A school coordinator must be assigned exactly one school.',
        'school_required' => 'Select at least one school.',
        'school_country' => "Selected schools must belong to the user's country.",
        'needs_country' => 'Assign a country to the user before adding coordinator scope.',
    ],
    'country' => [
        'in_use' => 'This country has regions, venues or users and cannot be deleted.',
    ],
    'region' => [
        'mismatch' => 'The selected region does not belong to the selected country.',
        'in_use' => 'This region has venues or users and cannot be deleted.',
    ],
    'user' => [
        'self_delete' => 'You cannot delete your own account.',
    ],
    'coordinator' => [
        'role_invalid' => 'Select a coordinator role (country or school coordinator).',
        'role_above_actor' => 'You may only manage school coordinators.',
        'country_outside_actor' => 'You may only manage coordinators in your own country.',
        'school_outside_actor' => 'Select venues from the ones assigned to you.',
    ],
    'coordinator_registration' => [
        'already_decided' => 'This registration has already been reviewed.',
        'email_taken' => 'An account with that e-mail address already exists. The registration cannot be approved.',
        'no_season' => 'There is no active season to assign the coordinator in. Start a season first.',
        'no_role' => 'The school coordinator role is missing. Seed the roles before approving registrations.',
        // Shown on the public form. It deliberately does not say which of the
        // two it is: telling a stranger "that address already has an account"
        // turns the form into a way of testing whether somebody is registered.
        'email_in_use' => 'That e-mail address cannot be used to register. If you already have an account, sign in instead.',
    ],
    'difficulty' => [
        'countries_required' => 'Select at least one country, or enable "all countries".',
        'category_in_use' => 'This category has levels and cannot be deleted. Delete its levels first.',
    ],
    'content' => [
        'tag_in_use' => 'This tag is used by questions and cannot be deleted.',
        // Deleting a round in use does not fail — it unhooks its exams and
        // publishing then writes nothing. Both messages name the way out.
        'round_in_use' => 'Exams sit in this round, so it cannot be deleted. Move them to another round first.',
        'round_is_practice' => 'This is the practice round. Deleting it would make practice results count as official ones, so it cannot be removed.',
        // The Phase 2 exit condition: nothing inconsistent may be published.
        // Each one names the way out, because the toggle that would fix it is
        // on the same screen.
        'test_without_questions' => 'An active test needs at least one question. Add one, or set the test to inactive to keep it as a draft.',
        'question_without_a_correct_answer' => 'A multiple-choice question needs one answer marked correct before it can be active. Without one it pays full marks to whoever leaves it blank.',
        'question_without_gaps' => 'A gap-filling question needs at least one gap with an accepted answer before it can be active.',
    ],
    'cms' => [
        'slug_reserved' => 'This address is used by the application. Choose another slug.',
        'category_in_use' => 'This category still has posts. Move or delete them first.',
    ],
    /*
     * What a competitor is told when the door they chose is shut. Never
     * "check your details": the details may be perfect and usually are, and
     * sending somebody back to re-read a correct candidate number is the exact
     * failure this replaced (2026-08-27).
     */
    'student' => [
        'competition_shut' => 'Live exams are not open right now.',
        'sample_shut' => 'No sample test is published just now.',
    ],
    'layout' => [
        'type_not_allowed' => 'That section cannot be placed in this zone.',
        'type_limit' => 'This zone already holds its :type section. There can be :max.',
        'order_mismatch' => 'The order must list every section in this zone, and only those.',
        'unknown_route' => ':path is not a screen on this site. Choose one from the list.',
    ],
];
