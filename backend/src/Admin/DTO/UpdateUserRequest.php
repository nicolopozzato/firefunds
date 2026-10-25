<?php

namespace App\Admin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateUserRequest
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

        public bool $isAdmin = false,
    ) {
    }
}
