<?php

namespace App\Report\DTO;

/**
 * Where the cash of the asset accounts is going, from today's balance on, and
 * the net worth it makes with what is held (at today's prices, no return assumed):
 * what is already entered for the days to come, what the recurrences will
 * move, and an estimate of the rest from the past, each column with the
 * balance at its end. In the user's currency, at today's rates (tomorrow's
 * aren't known).
 */
final readonly class CashFlowReportResponse
{
    /**
     * @param CashFlowPeriodResponse[] $periods
     * @param CashFlowAccountResponse[] $accounts in the user's order; those with no cash and nothing expected are left out
     */
    public function __construct(
        public string $currency,
        /** Y-m-d: the balance the forecast starts from is this day's end. */
        public string $today,
        public string $by,
        public string $opening,
        public string $openingNetWorth,
        public array $periods,
        public array $accounts,
        /** How many past months the estimate's mean was made of (fewer than asked when there is less history); 0: no estimate. */
        public int $estimateMonths,
    ) {
    }
}
