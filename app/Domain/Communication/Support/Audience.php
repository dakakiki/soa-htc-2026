<?php

declare(strict_types=1);

namespace App\Domain\Communication\Support;

/**
 * Who a message is for, as four lists that multiply.
 *
 * Roles AND countries AND venues AND people. An empty list is not a filter, so
 * an empty audience is every coordinator in the season, and
 * `roles: [country_coordinator], countries: [Serbia, Croatia]` is the country
 * coordinators of two countries — one sentence the administration actually
 * wants to say.
 *
 * 🪤 The first design stored a single `audience_type` and could not say it: it
 * forced a choice between "by role" and "by country" when the administration
 * means both at once (owner, 2026-09-15).
 */
final class Audience
{
    /**
     * @param  list<int>  $roles
     * @param  list<int>  $countries
     * @param  list<int>  $venues
     * @param  list<int>  $users
     */
    private function __construct(
        public readonly array $roles,
        public readonly array $countries,
        public readonly array $venues,
        public readonly array $users,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        return new self(
            self::ids($raw['roles'] ?? []),
            self::ids($raw['countries'] ?? []),
            self::ids($raw['venues'] ?? []),
            self::ids($raw['users'] ?? []),
        );
    }

    /**
     * @return array{roles: list<int>, countries: list<int>, venues: list<int>, users: list<int>}
     */
    public function toArray(): array
    {
        return [
            'roles' => $this->roles,
            'countries' => $this->countries,
            'venues' => $this->venues,
            'users' => $this->users,
        ];
    }

    /** Nothing narrowed: every coordinator of the season. */
    public function isEveryone(): bool
    {
        return $this->roles === [] && $this->countries === [] && $this->venues === [] && $this->users === [];
    }

    /**
     * Whole numbers, each one once, keys thrown away. What arrives from a form
     * is strings with gaps in the keys, and what a query needs is a plain list.
     *
     * @param  mixed  $values
     * @return list<int>
     */
    private static function ids($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($values, 'is_numeric'))));
    }
}
