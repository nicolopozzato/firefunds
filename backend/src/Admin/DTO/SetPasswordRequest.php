<?php

namespace App\Admin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,

        /** The user has to choose their own password at the next login. */
        public bool $mustChangePassword = true,
    ) {
    }
}
