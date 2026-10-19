<?php

namespace App\Transaction\DTO;

final readonly class DistributionsResponse
{
    /**
     * @param DistributionEntryResponse[] $categories
     * @param DistributionEntryResponse[] $sourceAccounts
     * @param DistributionEntryResponse[] $destinationAccounts
     */
    public function __construct(
        public array $categories,
        public array $sourceAccounts,
        public array $destinationAccounts,
    ) {
    }
}
