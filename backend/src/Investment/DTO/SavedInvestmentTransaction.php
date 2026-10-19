<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentTransaction;

/**
 * Result of creating/updating an investment transaction: the saved entity plus
 * whether the instrument's price history still has gaps through yesterday after
 * a best-effort backfill attempt, so the controller can warn the user without
 * failing the save itself.
 */
final readonly class SavedInvestmentTransaction
{
    public function __construct(
        public InvestmentTransaction $transaction,
        public bool $pricesIncomplete,
    ) {
    }
}
