<?php

namespace App\Report\DTO;

use App\Dashboard\DTO\DatedValueResponse;

/**
 * The income and expenses report, modelled on Firefly III's default report:
 * income and expenses per column, the asset accounts at the start and end,
 * the net worth over the range, expenses and income per category. Amounts
 * are added up as they are: one currency only (README, "Limitations").
 */
final readonly class IncomeExpenseReportResponse
{
    /**
     * @param ReportPeriodResponse[] $periods the columns, oldest first
     * @param ReportAccountResponse[] $accounts every asset account, in the user's order; inactive ones only if they held something
     * @param DatedValueResponse[] $netWorth day by day over the range, at most a few hundred points
     */
    public function __construct(
        public ?string $currencySymbol,
        /** Y-m-d, both included. */
        public string $from,
        public string $to,
        /** month | year */
        public string $by,
        public array $periods,
        public IncomeExpenseSummaryResponse $summary,
        public ?IncomeExpenseComparisonResponse $comparison,
        public array $accounts,
        public array $netWorth,
        public CategoryTableResponse $expenses,
        public CategoryTableResponse $income,
    ) {
    }
}
