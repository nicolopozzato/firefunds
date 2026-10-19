<?php

namespace App\Classification\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NameNotesRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255)]
        public string $name,

        public ?string $notes = null,
    ) {
    }
}
