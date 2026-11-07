<?php

namespace App\Api\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameApiTokenRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $name,
    ) {
    }
}
