<?php

namespace App\Investment\DTO;

/**
 * Money moved into and out of investments in one month: what the BUYs cost
 * (fees and taxes included) and what the SELLs brought back (net of them).
 */
final readonly class MonthlyInvestmentFlowResponse
{
    public function __construct(
        /** Y-m */
        public string $month,
        public string $bought,
        public string $sold,
        /** bought - sold: positive = money put in. */
        public string $net,
    ) {
    }
}
