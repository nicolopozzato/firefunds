<?php

namespace App\Dashboard\DTO;

use App\Transaction\DTO\TransactionResponse;

/** An asset account the dashboard shows: its balance line and latest transactions in the period. */
final readonly class DashboardAccountResponse
{
    /**
     * @param DatedValueResponse[] $history
     * @param TransactionResponse[] $transactions newest first
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $assetKind,
        public ?string $currencySymbol,
        /** At the end of the period; for an investment account free cash plus what is held. */
        public string $balance,
        /** Over the period, the same way as `balance`: day by day, or every few days over a long period (first and last day always in). */
        public array $history,
        public array $transactions,
    ) {
    }
}
