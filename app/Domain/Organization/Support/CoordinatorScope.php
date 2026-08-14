<?php

declare(strict_types=1);

namespace App\Domain\Organization\Support;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Region;
use App\Domain\Organization\Models\School;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared validation for a coordinator's role and school scope, applied by both
 * the store and update coordinator requests.
 */
final class CoordinatorScope
{
    public const ROLE_KEYS = [
        SystemRole::CountryCoordinator->value,
        SystemRole::SchoolCoordinator->value,
    ];

    /**
     * @param  list<int>  $schoolIds
     */
    public static function validate(
        Validator $validator,
        ?Role $role,
        array $schoolIds,
        ?int $countryId,
        ?int $regionId,
    ): void {
        if ($regionId && $countryId && ! Region::where('id', $regionId)->where('country_id', $countryId)->exists()) {
            $validator->errors()->add('region_id', trans('messages.region.mismatch'));
        }

        if ($role === null) {
            return;
        }

        if (! in_array($role->key, self::ROLE_KEYS, true)) {
            $validator->errors()->add('role_id', trans('messages.coordinator.role_invalid'));

            return;
        }

        // Cardinality by role: school coordinator = exactly one school,
        // country coordinator = one or more.
        if ($role->key === SystemRole::SchoolCoordinator->value && count($schoolIds) !== 1) {
            $validator->errors()->add('school_ids', trans('messages.assignment.school_single'));
        }
        if ($role->key === SystemRole::CountryCoordinator->value && count($schoolIds) < 1) {
            $validator->errors()->add('school_ids', trans('messages.assignment.school_required'));
        }

        if ($schoolIds === []) {
            return;
        }

        if ($countryId === null) {
            $validator->errors()->add('school_ids', trans('messages.assignment.needs_country'));

            return;
        }

        $outsideCountry = School::whereIn('id', $schoolIds)
            ->where('country_id', '!=', $countryId)
            ->exists();

        if ($outsideCountry) {
            $validator->errors()->add('school_ids', trans('messages.assignment.school_country'));
        }
    }
}
