<?php

namespace App\Investment\DTO;

final readonly class InvestmentPriceResponse
{
    public function __construct(
        public string $date,
        public string $price,
        public string $originalPrice,
    ) {
    }
}
