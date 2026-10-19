<?php

namespace App\Investment\DTO;

/** The part of a portfolio position held on one investment account. */
final readonly class PortfolioPositionAccountResponse
{
    public function __construct(
        public string $accountId,
        public string $accountName,
        public string $quantity,
        public string $costBasis,
        public string $averageCost,
        public ?string $currentValue,
        public ?string $gainAmount,
        public ?string $gainPercent,
    ) {
    }
}
