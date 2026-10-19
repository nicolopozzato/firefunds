<?php

namespace App\Investment\DTO;

final readonly class InvestmentLivePriceResponse
{
    public function __construct(
        public ?string $price,
        public string $marketState,
        /** When the provider was asked (the quote may come from LiveQuoteService's cache). */
        public string $fetchedAt,
        /** How often to ask again, in seconds. */
        public int $refreshSeconds,
    ) {
    }
}
