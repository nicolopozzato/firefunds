<?php

namespace App\Investment\DTO;

final readonly class InvestmentLivePriceResponse
{
    public function __construct(
        public ?string $price,
        public string $marketState,
        public string $fetchedAt,
    ) {
    }
}
