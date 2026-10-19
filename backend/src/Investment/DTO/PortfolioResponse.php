<?php

namespace App\Investment\DTO;

/**
 * Everything held on every investment account at once, the data behind
 * Investments -> Overview. Amounts are added up as they are: one currency
 * only (README, "Limitations").
 */
final readonly class PortfolioResponse
{
    /**
     * @param PortfolioPositionResponse[] $positions largest value first
     * @param PositionHistoryPointResponse[] $history
     * @param MonthlyInvestmentFlowResponse[] $monthlyFlows
     */
    public function __construct(
        /** Symbol of the investment accounts' currency (the first one's). */
        public ?string $currencySymbol,
        /** Market value of every open position (an unpriced one adds 0). */
        public string $marketValue,
        public string $costBasis,
        public string $gainAmount,
        public string $gainPercent,
        public string $estimatedTax,
        /** Free cash on the investment accounts, not invested yet. */
        public string $cash,
        /** Every asset account's balance plus the market value of what is held. */
        public string $netWorth,
        /** marketValue as a percentage of netWorth; null when there is no positive net worth. */
        public ?string $netWorthShare,
        public array $positions,
        /** Value and cost basis of the whole portfolio day by day, positions closed since included. */
        public array $history,
        public array $monthlyFlows,
    ) {
    }
}
