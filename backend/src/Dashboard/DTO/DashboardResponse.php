<?php

namespace App\Dashboard\DTO;

use App\Transaction\DTO\DistributionEntryResponse;

/**
 * Everything on the dashboard for one period. Amounts are added up as they
 * are: one currency only (README, "Limitations").
 */
final readonly class DashboardResponse
{
    /**
     * @param DashboardAccountResponse[] $accounts the active asset accounts set to show on the dashboard
     * @param DistributionEntryResponse[] $expensesByCategory largest first
     * @param DistributionEntryResponse[] $expenseAccounts largest first; a null id = no account
     * @param DistributionEntryResponse[] $revenueAccounts largest first; a null id = no account
     * @param DashboardInstrumentResponse[] $instruments largest value at the end first
     */
    public function __construct(
        /** Symbol of the asset accounts' currency (the first one's). */
        public ?string $currencySymbol,
        public DashboardSummaryResponse $summary,
        public array $accounts,
        public array $expensesByCategory,
        public array $expenseAccounts,
        public array $revenueAccounts,
        public array $instruments,
    ) {
    }
}
