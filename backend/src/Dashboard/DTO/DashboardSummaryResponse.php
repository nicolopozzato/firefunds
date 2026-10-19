<?php

namespace App\Dashboard\DTO;

/**
 * The headline figures of the period, over every account. "Start" is the end
 * of the day before the period begins, "end" the end of its last day.
 */
final readonly class DashboardSummaryResponse
{
    public function __construct(
        /** Deposits: from a revenue account or from nobody to an asset account. */
        public string $income,
        /** Withdrawals: from an asset account to an expense account or to nobody. */
        public string $expenses,
        /** income - expenses. */
        public string $balance,
        /** balance as a percentage of income; null without income. */
        public ?string $savingsRate,
        /** Every asset account's balance plus the market value of what is held. */
        public string $netWorthStart,
        public string $netWorthEnd,
        public string $netWorthChange,
        /** netWorthChange as a percentage of netWorthStart; null unless it is positive. */
        public ?string $netWorthChangePercent,
        /** Market value of what is held, on every investment account. */
        public string $investmentsStart,
        public string $investmentsEnd,
        /** What the period's BUYs cost (fees and taxes included) minus what its SELLs brought back. */
        public string $investmentContributions,
        /** What the market did: investmentsEnd - investmentsStart - investmentContributions. */
        public string $investmentGain,
    ) {
    }
}
