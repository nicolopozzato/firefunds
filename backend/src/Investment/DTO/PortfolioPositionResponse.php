<?php

namespace App\Investment\DTO;

/**
 * One instrument across every investment account holding it (the same ETF
 * bought on two brokers is one position here), with each account's share.
 */
final readonly class PortfolioPositionResponse
{
    /**
     * @param PortfolioPositionAccountResponse[] $accounts
     * @param PositionHistoryPointResponse[] $history
     */
    public function __construct(
        public string $instrumentId,
        public string $instrumentName,
        public ?string $instrumentTicker,
        public string $quantity,
        public string $costBasis,
        /** Total cost basis over total quantity, whatever account each unit was bought on. */
        /** Null when the accounts' units add up to zero (e.g. +10 on one, −10 on another). */
        public ?string $averageCost,
        /** The earliest of the accounts' opening days. */
        public ?string $openedOn,
        public ?string $currentPrice,
        public ?string $currentValue,
        public ?string $gainAmount,
        public ?string $gainPercent,
        public string $taxRate,
        /** Summed account by account: a loss on one broker doesn't offset a gain on another. */
        public ?string $estimatedTax,
        /** currentValue as a percentage of the portfolio's market value. */
        public ?string $portfolioShare,
        /** currentValue as a percentage of the net worth. */
        public ?string $netWorthShare,
        public array $accounts,
        public array $history,
    ) {
    }
}
