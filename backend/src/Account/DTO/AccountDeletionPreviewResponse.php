<?php

namespace App\Account\DTO;

/**
 * What deleting an account would affect, shown to the user before they
 * confirm: how many transactions reference it, and which accounts they could
 * be moved to instead of being deleted with it.
 */
final readonly class AccountDeletionPreviewResponse
{
    /**
     * @param array<array{id: string, name: string}> $transferTargets
     */
    public function __construct(
        /** Non-zero opening balance, moved as an adjustment (AccountAdjustment) on transfer; null if there is none. */
        public ?string $openingBalance,
        public int $transactions,
        /** Other changes to its balance (AccountAdjustment), moved or deleted with the transactions. */
        public int $adjustments,
        public int $investmentTransactions,
        public int $openPositions,
        public array $transferTargets,
        /** Recurring transactions using it: moved or deleted with the transactions. */
        public int $recurring = 0,
    ) {
    }
}
