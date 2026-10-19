<?php

namespace App\Account\DTO;

use App\Transaction\DTO\DistributionEntryResponse;

final readonly class AccountDistributionsResponse
{
    /**
     * @param DistributionEntryResponse[] $expenses
     * @param DistributionEntryResponse[] $income
     */
    public function __construct(
        public array $expenses,
        public array $income,
    ) {
    }
}
