<?php

namespace App\Report\DTO;

/**
 * A column of the cash flow report: a day, a week or a month (the first one
 * from today). Income, expenses and purchases by where they come from, in
 * the user's currency; transfers between the user's accounts are none of
 * them (they only move cash from one account to another).
 */
final readonly class CashFlowPeriodResponse
{
    /**
     * @param array{entered: string, recurring: string, estimated: string} $income
     * @param array{entered: string, recurring: string, estimated: string} $expense
     * @param array{entered: string, recurring: string, estimated: string} $invested
     * @param CashFlowItemResponse[] $items
     */
    public function __construct(
        /** Y-m-d, both included. */
        public string $start,
        public string $end,
        public array $income,
        public array $expense,
        public array $invested,
        /** Every account's cash at the column's end. */
        public string $balance,
        /** That cash and what is held (at today's prices, with what recurring purchases will have bought). */
        public string $netWorth,
        public array $items,
    ) {
    }
}
