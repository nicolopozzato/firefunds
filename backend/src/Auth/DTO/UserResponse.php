<?php

namespace App\Auth\DTO;

use App\Entity\User;

final readonly class UserResponse
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public array $roles,
        public bool $isAdmin,
        public string $locale,
        public string $theme,
        public int $pageSize,
        public string $timezone,
        public string $numberFormat,
        /** Percentage used to estimate the tax on capital gains (see User::$capitalGainsTaxRate). */
        public string $capitalGainsTaxRate,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: $user->getId(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            roles: $user->getRoles(),
            isAdmin: $user->isAdmin(),
            locale: $user->getLocale(),
            theme: $user->getTheme()->value,
            pageSize: $user->getPageSize(),
            timezone: $user->getTimezone(),
            numberFormat: $user->getNumberFormat(),
            capitalGainsTaxRate: $user->getCapitalGainsTaxRate(),
        );
    }
}
