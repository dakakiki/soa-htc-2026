<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Named application roles. Legacy numeric levels (10/5/1) exist only as a
 * migration map — new code must use these named values and policies, never
 * scattered numeric checks.
 */
enum Role: string
{
    case Admin = 'admin';
    case CountryCoordinator = 'country_coordinator';
    case SchoolCoordinator = 'school_coordinator';

    /**
     * Map a legacy `user_level` value to a role.
     */
    public static function fromLegacyLevel(int $level): self
    {
        return match ($level) {
            10 => self::Admin,
            5 => self::CountryCoordinator,
            1 => self::SchoolCoordinator,
            default => throw new \ValueError("Unknown legacy user_level: {$level}"),
        };
    }

    public function legacyLevel(): int
    {
        return match ($this) {
            self::Admin => 10,
            self::CountryCoordinator => 5,
            self::SchoolCoordinator => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::CountryCoordinator => 'Country coordinator',
            self::SchoolCoordinator => 'School coordinator',
        };
    }
}
