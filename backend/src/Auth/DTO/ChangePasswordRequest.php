<?php

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'New password is required')]
        #[Assert\Length(min: 8, minMessage: 'Password must be at least 8 characters')]
        public string $newPassword,

        /** Not asked when an administrator set the password to be changed (User::$mustChangePassword). */
        public ?string $currentPassword = null,
    ) {
    }
}
