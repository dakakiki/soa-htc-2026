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
    ],
    'difficulty' => [
        'countries_required' => 'Select at least one country, or enable "all countries".',
        'category_in_use' => 'This category has levels and cannot be deleted. Delete its levels first.',
    ],
    'content' => [
        'tag_in_use' => 'This tag is used by questions and cannot be deleted.',
    ],
];
