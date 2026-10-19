<?php

namespace App\Admin\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CurrencyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        public string $code,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 10)]
        public string $symbol,

        #[Assert\PositiveOrZero]
        public int $decimalPlaces,

        public bool $isEnabled,
    ) {
    }
}
