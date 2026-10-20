<?php

namespace App\Investment\DTO;

use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ManualPriceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Decimal]
        #[Assert\Positive]
        public string $price,
    ) {
    }
}
