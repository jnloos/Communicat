<?php

namespace App\Study\Planning;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * One drawn person of a place: a first and a last name from one subgroup.
 */
final class DrawnName
{
    public function __construct(
        public readonly string $gender,
        public readonly string $subgroup,
        public readonly string $firstNameId,
        public readonly string $firstName,
        public readonly string $lastName,
    ) {}

    public function fullName(): string
    {
        return "{$this->firstName} {$this->lastName}";
    }
}
