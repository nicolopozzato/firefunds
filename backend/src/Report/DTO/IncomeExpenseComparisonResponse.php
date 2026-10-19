<?php

namespace App\Report\DTO;

/** The same figures over the range compared with. */
final readonly class IncomeExpenseComparisonResponse
{
    /**
     * @param ReportPeriodResponse[] $periods its own columns, matched to the report's by position
     */
    public function __construct(
        /** Y-m-d, both included. */
        public string $from,
        public string $to,
        public array $periods,
        public IncomeExpenseSummaryResponse $summary,
    ) {
    }
}
