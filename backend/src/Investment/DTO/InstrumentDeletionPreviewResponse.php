<?php

namespace App\Investment\DTO;

/**
 * What deleting an instrument would affect, shown before the user confirms.
 */
final readonly class InstrumentDeletionPreviewResponse
{
    public function __construct(
        public int $investmentTransactions,
        public int $openPositions,
        /** Recurring purchases (PAC) of it, deleted with it. */
        public int $recurring = 0,
    ) {
    }
}
