<?php

namespace App\Investment\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ImportPricesRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Start date is required')]
        public string $from,

        #[Assert\NotBlank(message: 'End date is required')]
        public string $to,
    ) {
    }
}
