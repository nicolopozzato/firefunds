<?php

namespace App\Currency\DTO;

use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExchangeRateRequest
{
    public function __construct(
        /** What 1 unit of the currency is worth in the user's own currency that day. */
        #[Assert\NotBlank]
        #[Decimal(precision: 18, scale: 8)]
        #[Assert\Positive]
        public string $rate,
    ) {
    }
}
