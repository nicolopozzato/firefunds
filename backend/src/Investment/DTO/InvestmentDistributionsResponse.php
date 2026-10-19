<?php

namespace App\Investment\DTO;

use App\Transaction\DTO\DistributionEntryResponse;

/**
 * The pies above the investment transactions list. Instrument types come as
 * their code (`etf`, `stock`…) in both id and name: the frontend translates them.
 */
final readonly class InvestmentDistributionsResponse
{
    /**
     * @param DistributionEntryResponse[] $instrumentTypes
     * @param DistributionEntryResponse[] $instruments
     * @param DistributionEntryResponse[] $accounts
     */
    public function __construct(
        public array $instrumentTypes,
        public array $instruments,
        public array $accounts,
    ) {
    }
}
