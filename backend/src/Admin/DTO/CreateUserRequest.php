<?php

namespace App\Admin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateUserRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 100)]
        public string $firstName,

        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 100)]
        public string $lastName,

        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,

        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,

        public bool $isAdmin = false,

        /** The user has to choose their own password at the first login. */
        public bool $mustChangePassword = true,
    ) {
    }
}
