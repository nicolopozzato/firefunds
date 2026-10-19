<?php

namespace App\Report\DTO;

/**
 * The report's figures over its whole range. "Start" is the end of the day
 * before the range, "end" the end of its last day.
 */
final readonly class IncomeExpenseSummaryResponse
{
    public function __construct(
        /** Deposits: from a revenue account or from nobody to an asset account. */
        public string $income,
        /** Withdrawals: from an asset account to an expense account or to nobody. */
        public string $expenses,
        /** income - expenses */
        public string $balance,
        /** balance as a percentage of income; null without income. */
        public ?string $savingsRate,
        /** Moved from the other asset accounts to investment accounts, net of what came back. Part of the balance, not an expense. */
        public string $invested,
        /** Every asset account's balance plus the market value of what is held. */
        public string $netWorthStart,
        public string $netWorthEnd,
        public string $netWorthChange,
        /** Calendar months of the range up to today: what the monthly averages divide by. */
        public int $months,
    ) {
    }
}
