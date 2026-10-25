<?php

namespace App\Admin\DTO;

use App\Entity\User;

final readonly class AdminUserResponse
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public bool $isAdmin,
        public bool $isActive,
        /** UTC instant; null while the user is active. */
        public ?string $disabledAt,
        public bool $mustChangePassword,
        /** UTC instant of the last use of the app; null: never. */
        public ?string $lastSeenAt,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: (string) $user->getId(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            isAdmin: $user->isAdmin(),
            isActive: $user->isActive(),
            disabledAt: $user->getDisabledAt()?->format(\DateTimeInterface::ATOM),
            mustChangePassword: $user->mustChangePassword(),
            lastSeenAt: $user->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            createdAt: $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
