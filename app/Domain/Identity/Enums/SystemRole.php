<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Built-in system roles seeded on every environment. Custom roles created by an
 * admin live only in the `roles` table; these keys let code reference the
 * system ones and carry the legacy `user_level` (10/5/1) migration map.
 *
 * Authorization decisions check permissions, not these keys — the enum is for
 * seeding and migration mapping.
 */
enum SystemRole: string
{
    case Admin = 'admin';
    case CountryCoordinator = 'country_coordinator';
    case SchoolCoordinator = 'school_coordinator';
    case Student = 'student';

    public static function fromLegacyLevel(int $level): self
    {
        return match ($level) {
            10 => self::Admin,
            5 => self::CountryCoordinator,
            1 => self::SchoolCoordinator,
            default => throw new \ValueError("Unknown legacy user_level: {$level}"),
        };
    }

    public function legacyLevel(): ?int
    {
        return match ($this) {
            self::Admin => 10,
            self::CountryCoordinator => 5,
            self::SchoolCoordinator => 1,
            self::Student => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::CountryCoordinator => 'Country coordinator',
            self::SchoolCoordinator => 'School coordinator',
            self::Student => 'Student',
        };
    }
}
